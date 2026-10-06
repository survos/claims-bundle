<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Survos\ClaimsBundle\Exception\ClaimsUnavailableException;
use Doctrine\DBAL\Connection;

/**
 * Read access to the central claims store (the Postgres `claim` table), via the `claims`
 * connection this bundle registers from CLAIMS_DATABASE_URL — the SAME var the writer uses.
 * There is one claims DB and one env var; "read-only" is enforced by the Postgres role in the
 * DSN (a reader app uses a readonly claims role, so a write attempt fails at the DB), not by a second
 * connection/var. This is the consumer-side counterpart to {@see ClaimIngestor} (the writer):
 * apps (e.g. folio enrich) READ claims here keyed by media id.
 *
 * All schema knowledge for reads lives here, so callers depend on these methods rather
 * than the table layout — the same containment babel-bundle uses for translation memory.
 * Raw SQL/DBAL by design (no ORM entities in consumers → no per-app migrations).
 *
 * {@see isAvailable()} guards the optional connection: when the env/role isn't wired the
 * service still exists but reports unavailable, so callers can degrade gracefully.
 */
final class ClaimReader implements ClaimReaderInterface
{
    public function __construct(
        private readonly ?Connection $connection = null,
    ) {
    }

    /** True when the claims DB connection is wired (CLAIMS_DATABASE_URL set). */
    public function isAvailable(): bool
    {
        return $this->connection !== null;
    }

    /**
     * All claims for the given subjects (media ids), newest first. Subjects are mediary
     * media ids (deterministic base64-of-url); folio joins them via page.media_id.
     *
     * @param list<string> $subjectIds
     * @return list<array<string,mixed>>
     */
    public function forSubjects(array $subjectIds, ?string $scope = null): array
    {
        if ($subjectIds === []) {
            return [];
        }
        $conn = $this->require();

        $where = ['subject_id IN (:ids)'];
        $params = ['ids' => array_values($subjectIds)];
        $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING];
        if ($scope !== null && $scope !== '') {
            $where[] = 'scope = :scope';
            $params['scope'] = $scope;
        }

        return $this->decodeValues($conn->executeQuery(
            'SELECT scope, subject_id, predicate, source, value, confidence, basis, run_id, created_at
             FROM claim WHERE ' . implode(' AND ', $where) . ' ORDER BY subject_id, created_at DESC',
            $params,
            $types,
        )->fetchAllAssociative());
    }

    /**
     * The winning (manifested) claim per (subject, predicate): the most recent value
     * across sources. This is what enrich folds inline into Item/Page records.
     *
     * @param list<string> $subjectIds
     * @return list<array<string,mixed>>
     */
    public function manifestedForSubjects(array $subjectIds, ?string $scope = null): array
    {
        $seen = [];
        $out = [];
        foreach ($this->forSubjects($subjectIds, $scope) as $row) {
            // forSubjects() is ordered created_at DESC, so the first per (subject,predicate) wins.
            $key = $row['subject_id'] . '|' . $row['predicate'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Every claim for a whole scope (dataset), ordered newest-first per subject — the dataset-wide
     * counterpart to {@see forSubjects()}. Backs `claims:fetch`, which dumps a dataset's claims to
     * the vault claims.jsonl so enrich folds them locally and never hits the live DB per row.
     * The ordering lets the consumer manifest (first value per subject+predicate wins) without re-sorting.
     *
     * @return list<array<string,mixed>>
     */
    public function forScope(string $scope, array $excludeSources = []): array
    {
        $conn = $this->require();
        [$exclude, $params, $types] = self::excludeSources($excludeSources);

        return $this->decodeValues($conn->executeQuery(
            'SELECT scope, subject_type, subject_id, predicate, source, value, confidence, basis, run_id, created_at
             FROM claim WHERE scope = :scope' . $exclude . ' ORDER BY subject_id, created_at DESC',
            ['scope' => $scope] + $params,
            $types,
        )->fetchAllAssociative());
    }

    /**
     * @param list<string> $sources
     * @return array{string, array<string, list<string>>, array<string, \Doctrine\DBAL\ArrayParameterType>}
     */
    private static function excludeSources(array $sources): array
    {
        return $sources === []
            ? ['', [], []]
            : [' AND source NOT IN (:excludeSources)', ['excludeSources' => array_values($sources)], ['excludeSources' => \Doctrine\DBAL\ArrayParameterType::STRING]];
    }

    /**
     * Every claim_run for a whole scope (dataset) — the run-level counterpart to {@see forScope()}.
     * Backs `claims:fetch`, which also dumps a dataset's runs to the vault so the folio can ingest
     * them into a local `claim_run` table and serve "results of a task" without the live DB.
     *
     * @return list<array<string,mixed>>
     */
    public function runsForScope(string $scope, array $excludeSources = []): array
    {
        $conn = $this->require();
        [$exclude, $params, $types] = self::excludeSources($excludeSources);

        return $conn->executeQuery(
            'SELECT id, scope, subject_type, subject_id, source, model, prompt, response,
                    input_tokens, output_tokens, image_tokens, duration_ms, claim_count, created_at
             FROM claim_run WHERE scope = :scope' . $exclude . ' ORDER BY created_at DESC',
            ['scope' => $scope] + $params,
            $types,
        )->fetchAllAssociative();
    }

    /** Number of claims for a single subject (media id). */
    public function countForSubject(string $subjectId, ?string $scope = null): int
    {
        $conn = $this->require();
        $where = ['subject_id = :id'];
        $params = ['id' => $subjectId];
        if ($scope !== null && $scope !== '') {
            $where[] = 'scope = :scope';
            $params['scope'] = $scope;
        }

        return (int) $conn->executeQuery(
            'SELECT COUNT(*) FROM claim WHERE ' . implode(' AND ', $where),
            $params,
        )->fetchOne();
    }

    /**
     * Decode the jsonb `value` column. Raw DBAL returns a jsonb column as its JSON *text* — a scalar
     * string `1896` comes back as the literal `"1896"` (with quotes), a structured value as an opaque
     * JSON string. Without decoding, every consumer that writes these rows back out (claims:fetch →
     * vault claims.jsonl, enrich → folio) double-encodes: scalar strings gain wrapping quotes and
     * objects stay strings. Decode once here so callers get the real PHP value. NULL/non-string values
     * (a driver that already type-maps jsonb) pass through untouched; a decode error keeps the original.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decodeValues(array $rows): array
    {
        foreach ($rows as &$row) {
            if (\is_string($row['value'] ?? null)) {
                $decoded = json_decode($row['value'], true);
                if (json_last_error() === \JSON_ERROR_NONE) {
                    $row['value'] = $decoded;
                }
            }
        }
        unset($row);

        return $rows;
    }

    private function require(): Connection
    {
        if ($this->connection === null) {
            // ClaimsUnavailableException, not a bare RuntimeException, so "the store is not usable"
            // is one catchable type across both transports — a caller that degrades gracefully
            // shouldn't have to know whether it got DBAL or HTTP. It extends RuntimeException, so
            // anything already catching that is unaffected.
            throw new ClaimsUnavailableException(
                'ClaimReader has no connection. Set CLAIMS_DATABASE_URL so claims-bundle '
                . 'registers the `claims` connection (read-only enforced by the DSN role).',
            );
        }

        return $this->connection;
    }
}
