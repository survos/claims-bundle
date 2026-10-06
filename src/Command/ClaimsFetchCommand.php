<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Command;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Service\ClaimsVaultWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reader-side counterpart to `claims:export` (which is writer/ORM-only): pull a single dataset's
 * claims from mediary's central store into the vault claims.jsonl. This is the "fetch" step of the
 * pipeline:
 *
 *   claims:fetch <dataset>  →  dataset:enrich  →  folio:build
 *
 * Materialising claims to the vault once means `dataset:enrich` folds them from a local file
 * instead of querying the live DB per row — faster, and resilient to the claims DB being
 * unreachable at build time. The actual read+write lives in {@see ClaimsVaultWriter} (shared with
 * dataset:assemble's inline pre-enrich fetch); this command is just its CLI front.
 */
#[AsCommand('claims:fetch', 'Fetch a dataset\'s claims from mediary into the vault claims.jsonl (so enrich reads locally, never the live DB).')]
final class ClaimsFetchCommand
{
    public function __construct(
        private readonly ClaimsVaultWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dataset key / claim scope, e.g. mus/fortepan')]
        string $dataset,
        #[Option('Write to this path instead of the vault default (DataPaths::claimsFile).')]
        ?string $output = null,
        #[Option('Also fetch @import claims (the client\'s own imported metadata), which are left out by default.')]
        bool $includeImported = false,
    ): int {
        if (!$this->writer->isAvailable()) {
            $io->error("No claims connection. Set CLAIMS_DATABASE_URL so the `claims` connection is registered (read-only enforced by the DSN role).");
            return Command::FAILURE;
        }

        try {
            $result = $this->writer->write($dataset, $output, $includeImported ? [] : [Claim::SOURCE_IMPORT]);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Fetched %d claim(s) for "%s" → %s', $result['claims'], $dataset, $result['output']));
        if ($result['runsOutput'] !== null) {
            $io->success(sprintf('Fetched %d run(s) for "%s" → %s', $result['runs'], $dataset, $result['runsOutput']));
        }

        return Command::SUCCESS;
    }
}
