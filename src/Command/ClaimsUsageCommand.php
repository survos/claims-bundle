<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What an AI run actually consumed, per scope.
 *
 * ClaimRun has recorded model + input/output tokens all along, but reading it meant
 * hand-writing SQL against the claims connection, so in practice nobody did -- an
 * enrichment run's cost was an unanswered question with the answer already in the
 * database. This is that query, with a name.
 *
 * Cost is reported only for models listed under `survos_claims.model_rates`. Rates are
 * deliberately config rather than constants: they change, they differ per account and
 * per commitment, and a stale price printed as fact is worse than no price. Tokens are
 * always reported, so the command is useful before anyone configures a single rate.
 */
#[AsCommand('claims:usage', 'Report token usage (and cost, where rates are configured) for AI runs in a scope.')]
final class ClaimsUsageCommand
{
    /** @param array<string, array{input?: float, output?: float, per_call?: float}> $modelRates */
    public function __construct(
        private readonly ?Connection $claimsConnection = null,
        private readonly array $modelRates = [],
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Scope to report on, e.g. ssai/daveglassner. Omit for every scope.')]
        ?string $scope = null,
        #[Option('Only runs at or after this date/time, e.g. 2026-09-23.')]
        ?string $since = null,
        #[Option('Only runs before this date/time.')]
        ?string $until = null,
        #[Option('Break the totals down by scope as well as source and model.')]
        bool $byScope = false,
    ): int {
        if ($this->claimsConnection === null) {
            $io->error('No claims connection. Set CLAIMS_DATABASE_URL so the `claims` connection is registered.');

            return Command::FAILURE;
        }

        $where = [];
        $params = [];
        if ($scope !== null) {
            $where[] = 'scope = :scope';
            $params['scope'] = $scope;
        }
        if ($since !== null) {
            $where[] = 'created_at >= :since';
            $params['since'] = $since;
        }
        if ($until !== null) {
            $where[] = 'created_at < :until';
            $params['until'] = $until;
        }

        $groupCols = $byScope ? ['scope', 'source', 'model'] : ['source', 'model'];
        $select = implode(', ', $groupCols);

        $sql = sprintf(
            'SELECT %s,
                    COUNT(*)                       AS calls,
                    COALESCE(SUM(input_tokens), 0)  AS in_tokens,
                    COALESCE(SUM(output_tokens), 0) AS out_tokens,
                    MIN(created_at)                 AS first_run,
                    MAX(created_at)                 AS last_run
               FROM claim_run
              %s
              GROUP BY %s
              ORDER BY calls DESC',
            $select,
            $where === [] ? '' : 'WHERE ' . implode(' AND ', $where),
            $select,
        );

        $rows = $this->claimsConnection->fetchAllAssociative($sql, $params);

        if ($rows === []) {
            $io->warning('No runs matched.');

            return Command::SUCCESS;
        }

        $headers = [...$groupCols, 'calls', 'in tokens', 'out tokens', 'cost (USD)', 'first', 'last'];
        $table = [];
        $totalCalls = 0;
        // Page-priced models (Mistral OCR) report no tokens at all. Averaging input
        // tokens over every call would divide a real number by an inflated denominator
        // and understate the per-image prompt cost -- the one figure this report exists
        // to surface. Only calls that reported tokens count toward the average.
        $tokenCalls = 0;
        $totalIn = 0;
        $totalOut = 0;
        $totalCost = 0.0;
        $anyUnpriced = false;

        foreach ($rows as $row) {
            $calls = (int) $row['calls'];
            $in = (int) $row['in_tokens'];
            $out = (int) $row['out_tokens'];
            $cost = $this->cost((string) ($row['model'] ?? ''), $calls, $in, $out);

            $totalCalls += $calls;
            if ($in > 0 || $out > 0) {
                $tokenCalls += $calls;
            }
            $totalIn += $in;
            $totalOut += $out;
            if ($cost === null) {
                $anyUnpriced = true;
            } else {
                $totalCost += $cost;
            }

            $line = [];
            foreach ($groupCols as $col) {
                $line[] = $row[$col] ?? '—';
            }
            $table[] = [
                ...$line,
                number_format($calls),
                number_format($in),
                number_format($out),
                $cost === null ? '—' : sprintf('%.2f', $cost),
                substr((string) $row['first_run'], 0, 19),
                substr((string) $row['last_run'], 0, 19),
            ];
        }

        $table[] = new TableSeparator();
        $table[] = [
            ...array_fill(0, \count($groupCols) - 1, ''),
            '<info>TOTAL</info>',
            number_format($totalCalls),
            number_format($totalIn),
            number_format($totalOut),
            sprintf('%.2f', $totalCost),
            '',
            '',
        ];

        $io->table($headers, $table);

        // Per-call input tokens is the number worth watching: it is what a prompt or an
        // oversized image quietly multiplies across a whole collection, and it is the
        // lever that decides whether the next run costs cents or hundreds.
        if ($tokenCalls > 0 && $totalIn > 0) {
            $io->writeln(sprintf(
                '  Average input tokens per token-reporting call: <comment>%s</comment> (over %s call(s))',
                number_format($totalIn / $tokenCalls),
                number_format($tokenCalls),
            ));
        }

        if ($anyUnpriced) {
            $io->note(
                'Some models have no entry in survos_claims.model_rates, so their cost is blank and '
                . 'excluded from the total. Add them to price the run; token counts above are complete either way.',
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Null when the model has no configured rate — deliberately distinct from 0.00, so an
     * unpriced model reads as "unknown" rather than "free".
     */
    private function cost(string $model, int $calls, int $inTokens, int $outTokens): ?float
    {
        $rate = $this->rateFor($model);
        if ($rate === null) {
            return null;
        }

        return $inTokens / 1_000_000 * (float) ($rate['input'] ?? 0.0)
            + $outTokens / 1_000_000 * (float) ($rate['output'] ?? 0.0)
            + $calls * (float) ($rate['per_call'] ?? 0.0);
    }

    /**
     * Exact match first, then longest prefix: providers record a dated id
     * ("gpt-4o-mini-2024-07-18") while rate cards are published against the alias
     * ("gpt-4o-mini"), so one config entry should cover every dated build of it.
     *
     * @return array{input?: float, output?: float, per_call?: float}|null
     */
    private function rateFor(string $model): ?array
    {
        if ($model === '') {
            return null;
        }
        if (isset($this->modelRates[$model])) {
            return $this->modelRates[$model];
        }

        $best = null;
        $bestLength = 0;
        foreach ($this->modelRates as $prefix => $rate) {
            if (str_starts_with($model, (string) $prefix) && \strlen((string) $prefix) > $bestLength) {
                $best = $rate;
                $bestLength = \strlen((string) $prefix);
            }
        }

        return $best;
    }
}
