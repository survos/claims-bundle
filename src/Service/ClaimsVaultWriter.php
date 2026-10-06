<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\DataContracts\Path\DataPaths;
use Survos\JsonlBundle\IO\JsonlWriter;

/**
 * Materialise a dataset's claims (and runs) from the central claims DB into the vault JSONL — the
 * read-side "fetch" of the pipeline ({@see ClaimReaderInterface} → vault `claims.jsonl`/`claim-runs.jsonl`).
 *
 * Single owner of the vault row shape: both `claims:fetch` and `dataset:assemble`'s inline
 * pre-enrich fetch call this, so the shape never drifts between them.
 *
 * Depends on the INTERFACE, not the DBAL reader: this runs on reader-only consumers, which are
 * exactly the apps that should not need a Postgres DSN. With `survos_claims.reader: api` it
 * materialises the vault over mediary's HTTP API instead, same rows either way.
 */
final class ClaimsVaultWriter
{
    public function __construct(
        private readonly ClaimReaderInterface $reader,
        // Optional: dataset-bundle owns the vault layout. Without it, callers must pass $output.
        private readonly ?DataPaths $dataPaths = null,
    ) {
    }

    /** True when the claims store is reachable-in-principle (delegates to the reader). */
    public function isAvailable(): bool
    {
        return $this->reader->isAvailable();
    }

    /**
     * Write <scope>'s claims to $output (default: the vault claims.jsonl) and its runs to the sibling
     * claim-runs.jsonl. Runs are best-effort — an older claims DB may lack the claim_run table, so a
     * runs failure leaves runs=0 but never fails the claims write.
     *
     * $excludeSources defaults to the client's own imported metadata: the caller sent those
     * claims and already holds the data, so folding them back only re-ingests its own fields
     * (mus/cleveland: 180,076 of 180,262 claims were @import echoes, ~90 s of folio build).
     *
     * @param list<string> $excludeSources
     * @return array{claims:int, runs:int, output:string, runsOutput:?string}
     */
    public function write(string $scope, ?string $output = null, array $excludeSources = [Claim::SOURCE_IMPORT]): array
    {
        $output ??= $this->dataPaths?->claimsFile($scope);
        if ($output === null) {
            throw new \RuntimeException('No output path: dataset-bundle (DataPaths) is unavailable, so pass $output explicitly.');
        }

        // Read BEFORE opening the writer. JsonlWriter::open() truncates immediately, so with the
        // read inside the write block any failure past that point leaves an empty claims.jsonl --
        // which enrich then folds as "this dataset has no claims", and _folio keeps that answer
        // until someone re-runs enrich by hand. Both readers return fully materialised arrays, so
        // this costs nothing and makes the destructive step unreachable unless the read succeeded.
        $rows = $this->reader->forScope($scope, $excludeSources);

        $count = 0;
        $writer = JsonlWriter::open($output);
        $completed = false;
        try {
            foreach ($rows as $row) {
                // DBAL returns snake_case columns; write the canonical claim shape the vault uses.
                $writer->write([
                    'scope' => $row['scope'],
                    'subjectType' => $row['subject_type'],
                    'subjectId' => $row['subject_id'],
                    'predicate' => $row['predicate'],
                    'source' => $row['source'],
                    'value' => $row['value'],
                    'confidence' => $row['confidence'],
                    'basis' => $row['basis'],
                    'runId' => $row['run_id'],
                    'createdAt' => $row['created_at'],
                ]);
                ++$count;
            }
            $completed = true;
        } finally {
            $completed ? $writer->finish() : $writer->close();
        }

        $runCount = 0;
        $runsOutput = $this->dataPaths?->claimsFile($scope, 'claim-runs.jsonl');
        if ($runsOutput !== null) {
            try {
                // Same ordering as the claims write above, for the same reason.
                $runRows = $this->reader->runsForScope($scope, $excludeSources);

                $rw = JsonlWriter::open($runsOutput);
                $ok = false;
                try {
                    foreach ($runRows as $r) {
                        $rw->write([
                            'id' => $r['id'],
                            'scope' => $r['scope'],
                            'subjectType' => $r['subject_type'],
                            'subjectId' => $r['subject_id'],
                            'source' => $r['source'],
                            'model' => $r['model'],
                            'prompt' => $r['prompt'],
                            'response' => $r['response'],
                            'inputTokens' => $r['input_tokens'],
                            'outputTokens' => $r['output_tokens'],
                            'imageTokens' => $r['image_tokens'],
                            'durationMs' => $r['duration_ms'],
                            'claimCount' => $r['claim_count'],
                            'createdAt' => $r['created_at'],
                        ]);
                        ++$runCount;
                    }
                    $ok = true;
                } finally {
                    $ok ? $rw->finish() : $rw->close();
                }
            } catch (\Throwable) {
                $runCount = 0; // older claims DB without claim_run — claims still written
                $runsOutput = null;
            }
        }

        return ['claims' => $count, 'runs' => $runCount, 'output' => $output, 'runsOutput' => $runsOutput];
    }
}
