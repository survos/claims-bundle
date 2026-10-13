<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ClaimsBundle\Entity\Claim;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'claims:import',
    description: 'Import claims from JSONL on stdin or from a file.',
)]
final class ClaimsImportCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Read from a .jsonl or .jsonl.gz file instead of stdin.')]
        ?string $input = null,
        #[Option('Override scope for every imported row.')]
        ?string $scope = null,
        #[Option('Flush every N imported rows.')]
        int $batchSize = 250,
        #[Option('Skip rows whose claim id already exists.')]
        bool $skipExisting = true,
    ): int {
        if (null !== $input && !class_exists(JsonlReader::class)) {
            $io->error("jsonl-bundle is not installed.\n\ncomposer req survos/jsonl-bundle");

            return Command::FAILURE;
        }

        $batchSize = max(1, $batchSize);
        $imported = 0;
        $skipped = 0;
        $line = 0;

        foreach ($this->rows($input) as $row) {
            ++$line;

            if (null !== $scope) {
                $row['scope'] = $scope;
            }

            try {
                $claim = Claim::fromArray($row);
            } catch (\Throwable $e) {
                $io->error(sprintf('Invalid JSONL row at line %d: %s', $line, $e->getMessage()));

                return Command::FAILURE;
            }

            if ($skipExisting && $this->em->find(Claim::class, $claim->id)) {
                ++$skipped;
                continue;
            }

            $this->em->persist($claim);
            ++$imported;

            if (($imported % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        $this->em->flush();
        $this->em->clear();

        $io->success(sprintf('Imported %d claim(s)%s.', $imported, $skipped > 0 ? sprintf(', skipped %d existing.', $skipped) : ''));

        return Command::SUCCESS;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function rows(?string $input): iterable
    {
        if (null !== $input) {
            foreach (JsonlReader::open($input) as $row) {
                yield $row;
            }

            return;
        }

        while (($line = fgets(\STDIN)) !== false) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('Encountered a non-object JSONL row on stdin.');
            }

            yield $decoded;
        }
    }
}
