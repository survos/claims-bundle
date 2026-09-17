<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Entity\ClaimRun;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\IO\JsonlWriterOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Ulid;

/**
 * Writes a batch of RawClaims for a given subject and source.
 *
 * JSONL-first: the scope's `claims.jsonl` (in the vault) is the durable authority for
 * every claim that flows through the ai-workflow. The Doctrine Claim/ClaimRun rows are
 * a rebuildable index of the entity under examination — convenient for queries, but not
 * the source of truth. A null scope (ad-hoc / one-shot subject) skips the log.
 *
 * Rerun semantics: on record(), all existing DB claims AND the prior ClaimRun for
 * (scope, subjectType, subjectId, source) are removed first, then fresh ones are
 * persisted sharing one runId. claims.jsonl is append-only and only receives claims whose
 * predicate+value the DB did not already hold for that subject+source, so a rerun that
 * asserts nothing new appends nothing; changed values are appended and supersession is
 * resolved by reading (latest wins per subject+predicate+source). The caller decides when
 * to flush the DB.
 */
final class ClaimIngestor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaimRepository $claims,
        private readonly ClaimRunRepository $runs,
        private readonly ?DataPaths $dataPaths = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<RawClaim> $rawClaims
     */
    public function record(
        ?string $scope,
        string $subjectType,
        string $subjectId,
        string $source,
        array $rawClaims,
        ?RunMeta $meta = null,
        ?string $runId = null,
    ): ClaimRun {
        return $this->recordOne($scope, $subjectType, $subjectId, $source, $rawClaims, $meta, $runId, null);
    }

    /**
     * Batch variant of record() for callers ingesting claims for many subjects
     * in one request/pass (e.g. mediary's media:sync batch endpoint). record()
     * opens, marks-complete, and closes a fresh vault JsonlWriter per call —
     * each open/close pays its own sidecar stat+persist cost, capping calls at
     * roughly 10/sec regardless of payload size (the exact per-row cost
     * STATE_FLUSH_ROWS batching in JsonlWriter already solves *within* one
     * writer session — but a writer opened fresh per item never benefits from
     * it). Items are grouped by scope so one writer is shared per distinct
     * scope in the batch (almost always just one) instead of per item.
     *
     * @param list<array{scope: ?string, subjectType: string, subjectId: string, source: string, rawClaims: list<RawClaim>, meta?: ?RunMeta, runId?: ?string}> $items
     * @return list<ClaimRun>
     */
    public function recordBatch(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // Preload stale claims/runs per (scope, subjectType, source) group — one
        // query per group instead of the 2 queries per item recordOne() does on
        // its own. Groups are almost always singular in practice (one dataset,
        // one subjectType, one source per batch). Keyed by groupKey+subjectId
        // (not subjectId alone) so the same subjectId appearing in two groups
        // (e.g. two different sources) doesn't collide.
        $groups = [];
        foreach ($items as $item) {
            $groupKey = ($item['scope'] ?? '') . "\0" . $item['subjectType'] . "\0" . $item['source'];
            $groups[$groupKey]['scope'] ??= $item['scope'];
            $groups[$groupKey]['subjectType'] ??= $item['subjectType'];
            $groups[$groupKey]['source'] ??= $item['source'];
            $groups[$groupKey]['subjectIds'][] = $item['subjectId'];
        }

        $staleClaims = [];
        $staleRuns = [];
        foreach ($groups as $groupKey => $group) {
            foreach ($this->claims->findForSubjectsAndSource($group['subjectType'], $group['subjectIds'], $group['source'], $group['scope']) as $subjectId => $rows) {
                $staleClaims[$groupKey . "\0" . $subjectId] = $rows;
            }
            foreach ($this->runs->findForSubjectsAndSource($group['subjectType'], $group['subjectIds'], $group['source'], $group['scope']) as $subjectId => $rows) {
                $staleRuns[$groupKey . "\0" . $subjectId] = $rows;
            }
        }

        $writers = [];
        $runs = [];

        try {
            foreach ($items as $item) {
                $scope = $item['scope'];
                $scopeKey = $scope ?? '';
                $groupKey = $scopeKey . "\0" . $item['subjectType'] . "\0" . $item['source'];
                $subjectKey = $groupKey . "\0" . $item['subjectId'];

                if (!array_key_exists($scopeKey, $writers)) {
                    $writers[$scopeKey] = $this->openVaultWriter($scope);
                }

                $runs[] = $this->recordOne(
                    $scope,
                    $item['subjectType'],
                    $item['subjectId'],
                    $item['source'],
                    $item['rawClaims'],
                    $item['meta'] ?? null,
                    $item['runId'] ?? null,
                    $writers[$scopeKey],
                    $staleClaims[$subjectKey] ?? [],
                    $staleRuns[$subjectKey] ?? [],
                );
            }

            return $runs;
        } finally {
            foreach ($writers as $scopeKey => $writer) {
                if ($writer === null) {
                    continue;
                }
                try {
                    $writer->finish(markComplete: true);
                } catch (\Throwable $e) {
                    $this->logger->warning('Claims persisted to DB but vault JSONL batch close failed for {scope}: {err}', [
                        'scope' => $scopeKey, 'err' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @param list<RawClaim> $rawClaims
     * @param list<Claim>|null $preloadedStaleClaims when non-null, used instead of a fresh
     *        findForSubjectAndSource() query (recordBatch() preloads these per group)
     * @param list<ClaimRun>|null $preloadedStaleRuns same, for runs
     */
    private function recordOne(
        ?string $scope,
        string $subjectType,
        string $subjectId,
        string $source,
        array $rawClaims,
        ?RunMeta $meta,
        ?string $runId,
        ?JsonlWriter $sharedWriter,
        ?array $preloadedStaleClaims = null,
        ?array $preloadedStaleRuns = null,
    ): ClaimRun {
        $runId ??= (string) new Ulid();

        // ── DB index (queryable; what claims:fetch reads): delete-then-insert ─────
        $staleClaims = $preloadedStaleClaims ?? $this->claims->findForSubjectAndSource($subjectType, $subjectId, $source, $scope);
        $alreadyLogged = [];
        foreach ($staleClaims as $stale) {
            $alreadyLogged[self::claimKey($stale->predicate, $stale->value)] = true;
            $this->em->remove($stale);
        }
        $staleRuns = $preloadedStaleRuns ?? $this->runs->findForSubjectAndSource($subjectType, $subjectId, $source, $scope);
        foreach ($staleRuns as $staleRun) {
            $this->em->remove($staleRun);
        }

        $run = new ClaimRun(
            scope:        $scope,
            subjectType:  $subjectType,
            subjectId:    $subjectId,
            source:       $source,
            model:        $meta?->model,
            prompt:       $meta?->prompt,
            response:     $meta?->response,
            inputTokens:  $meta?->inputTokens,
            outputTokens: $meta?->outputTokens,
            imageTokens:  $meta?->imageTokens,
            durationMs:   $meta?->durationMs,
            claimCount:   count($rawClaims),
            id:           $runId,
        );
        $this->em->persist($run);

        foreach ($rawClaims as $rawClaim) {
            $claim = new Claim(
                scope:       $scope,
                subjectType: $subjectType,
                subjectId:   $subjectId,
                predicate:   $rawClaim->predicate,
                source:      $source,
                value:       $rawClaim->value,
                confidence:  $rawClaim->confidence,
                basis:       $rawClaim->basis,
                runId:       $runId,
            );
            $this->em->persist($claim);
        }

        // ── Vault JSONL mirror: best-effort. The DB (above) is the queryable store that
        // claims:fetch reads and can rebuild the JSONL from, so a vault this app doesn't own
        // (e.g. the central mediary service writing a dataset's vault) must not abort the claim.
        // Append-only means new assertions only: a claim the DB already held for this
        // (subject, source) with the same predicate+value is already in the log, so re-importing
        // appends nothing. Without this every media:sync pass re-logged all @import claims
        // (mus/fpus: 18,075 claims became 783,875 lines).
        $newClaims = array_values(array_filter(
            $rawClaims,
            static fn (RawClaim $c): bool => !isset($alreadyLogged[self::claimKey($c->predicate, $c->value)]),
        ));
        try {
            if ($sharedWriter !== null) {
                $this->writeClaimsJsonl($sharedWriter, $scope, $subjectType, $subjectId, $source, $newClaims, $runId);
            } else {
                $this->appendToClaimsJsonl($scope, $subjectType, $subjectId, $source, $newClaims, $runId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Claims persisted to DB but vault JSONL append failed for {scope}/{subject}: {err}', [
                'scope' => $scope, 'subject' => $subjectId, 'err' => $e->getMessage(),
            ]);
        }

        return $run;
    }

    /** Open (append mode) the shared vault writer for recordBatch(), or null when there's no vault to write to. */
    private function openVaultWriter(?string $scope): ?JsonlWriter
    {
        if (null === $this->dataPaths || null === $scope || '' === $scope) {
            return null;
        }

        return JsonlWriter::open($this->dataPaths->claimsFile($scope), 'a', JsonlWriterOptions::noLock());
    }

    /** Identity of a claim within one (scope, subjectType, subjectId, source): predicate + value. */
    private static function claimKey(string $predicate, mixed $value): string
    {
        return $predicate . "\0" . json_encode(self::sortKeys($value), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** jsonb does not keep object key order, so a structured value read back from the DB must compare key-sorted. */
    private static function sortKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }

    /**
     * Commit pending claim writes on THIS ingestor's EM — which may be a dedicated shared-claims EM
     * (survos_claims.entity_manager), not the app's default. Callers must flush via this, not their
     * own EntityManager, or claims silently never commit when a separate EM is configured.
     */
    public function flush(): void
    {
        $this->em->flush();
    }

    /**
     * @param list<RawClaim> $rawClaims
     */
    private function appendToClaimsJsonl(?string $scope, string $subjectType, string $subjectId, string $source, array $rawClaims, string $runId): void
    {
        if (null === $this->dataPaths || null === $scope || '' === $scope || [] === $rawClaims) {
            return;
        }

        $writer = JsonlWriter::open($this->dataPaths->claimsFile($scope), 'a', JsonlWriterOptions::noLock());
        try {
            $this->writeClaimsJsonl($writer, $scope, $subjectType, $subjectId, $source, $rawClaims, $runId);
        } finally {
            $writer->finish(markComplete: true);
        }
    }

    /**
     * @param list<RawClaim> $rawClaims
     */
    private function writeClaimsJsonl(JsonlWriter $writer, ?string $scope, string $subjectType, string $subjectId, string $source, array $rawClaims, string $runId): void
    {
        if ([] === $rawClaims) {
            return;
        }

        $createdAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        foreach ($rawClaims as $rawClaim) {
            $writer->write([
                'scope' => $scope,
                'subjectType' => $subjectType,
                'subjectId' => $subjectId,
                'predicate' => $rawClaim->predicate,
                'source' => $source,
                'value' => $rawClaim->value,
                'confidence' => $rawClaim->confidence,
                'basis' => $rawClaim->basis,
                'runId' => $runId,
                'createdAt' => $createdAt,
            ]);
        }
    }
}
