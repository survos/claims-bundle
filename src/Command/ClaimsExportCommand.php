<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Command;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Entity\ClaimRun;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\DataContracts\Path\DataPaths;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'claims:export',
    description: 'Export claims (and optionally runs) as JSONL to 40_ai/ or stdout.',
)]
final class ClaimsExportCommand
{
    public function __construct(
        private readonly ClaimRepository    $claims,
        private readonly ClaimRunRepository $runs,
        private readonly ?DataPaths         $dataPaths = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Dataset key — auto-resolves scope and output paths (e.g. fortepan/hu).')]
        ?string $dataset = null,
        #[Option('Restrict export to one scope.')]
        ?string $scope = null,
        #[Option('Restrict export to one subject type.')]
        ?string $subjectType = null,
        #[Option('Restrict export to one source.')]
        ?string $source = null,
        #[Option('Write claims to this path instead of the dataset default.')]
        ?string $output = null,
        #[Option('Also export ClaimRun audit rows to runs.jsonl alongside claims.jsonl.')]
        bool $includeRuns = false,
        #[Option('Include rendered prompt text in run rows (large — omitted by default).')]
        bool $includePrompt = false,
    ): int {
        $runsOutput = null;

        if ($dataset) {
            $scope      ??= $dataset;
            $output     ??= $this->dataPaths?->claimsFile($dataset);
            if ($includeRuns && $this->dataPaths) {
                $runsOutput = $this->dataPaths->claimsFile($dataset, 'runs.jsonl');
            }
        }

        if (($output !== null || $runsOutput !== null) && !class_exists(JsonlWriter::class)) {
            $io->error("jsonl-bundle is not installed.\n\ncomposer req survos/jsonl-bundle");
            return Command::FAILURE;
        }

        // ── Claims ───────────────────────────────────────────────────────────
        $count     = 0;
        $writer    = $output !== null ? JsonlWriter::open($output) : null;
        $completed = false;

        try {
            foreach ($this->claims->iterateForExport($scope, $subjectType, $source) as $claim) {
                \assert($claim instanceof Claim);
                $row = $claim->toArray();

                if ($writer !== null) {
                    $writer->write($row);
                } else {
                    fwrite(\STDOUT, \json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
                }
                ++$count;
            }
            $completed = true;
        } finally {
            if ($writer !== null) {
                $completed ? $writer->finish() : $writer->close();
            }
        }

        $output !== null
            ? $io->success(sprintf('Exported %d claim(s) to %s.', $count, $output))
            : $io->writeln(sprintf('Exported %d claim(s).', $count), \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        // ── Runs (optional) ──────────────────────────────────────────────────
        if (!$includeRuns) {
            return Command::SUCCESS;
        }

        $runCount     = 0;
        $runsWriter   = $runsOutput !== null ? JsonlWriter::open($runsOutput) : null;
        $runsCompleted = false;

        try {
            foreach ($this->runs->iterateForExport($scope, $subjectType) as $run) {
                \assert($run instanceof ClaimRun);
                $row = [
                    'id'          => $run->id,
                    'createdAt'   => $run->createdAt->format(\DateTimeInterface::ATOM),
                    'scope'       => $run->scope,
                    'subjectType' => $run->subjectType,
                    'subjectId'   => $run->subjectId,
                    'source'      => $run->source,
                    'model'       => $run->model,
                    'inputTokens' => $run->inputTokens,
                    'outputTokens'=> $run->outputTokens,
                    'imageTokens' => $run->imageTokens,
                    'durationMs'  => $run->durationMs,
                    'claimCount'  => $run->claimCount,
                ];
                if ($includePrompt) {
                    $row['prompt']   = $run->prompt;
                    $row['response'] = $run->response;
                }

                if ($runsWriter !== null) {
                    $runsWriter->write($row);
                } else {
                    fwrite(\STDOUT, \json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
                }
                ++$runCount;
            }
            $runsCompleted = true;
        } finally {
            if ($runsWriter !== null) {
                $runsCompleted ? $runsWriter->finish() : $runsWriter->close();
            }
        }

        $runsOutput !== null
            ? $io->success(sprintf('Exported %d run(s) to %s.', $runCount, $runsOutput))
            : $io->writeln(sprintf('Exported %d run(s).', $runCount), \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
