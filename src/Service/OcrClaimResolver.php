<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Survos\ClaimsBundle\Entity\Claim;

/** Select a complete provider/run, never merge one engine's text with another's boxes. */
final class OcrClaimResolver
{
    public const TINY = 'ocr_tiny';
    public const MISTRAL = 'ocr_mistral';
    public const PAGE = 'ai:ocrPage';
    public const BLOCK = 'ai:layoutBlock';
    public const IMAGE = 'ai:imageBlock';
    public const TABLE = 'ai:table';
    public const PREDICATES = [Claim::PRED_OCR_TEXT, self::PAGE, self::BLOCK, self::IMAGE, self::TABLE];

    /** @return array<string, array{source: string, runId: string, text: string, pages: array, blocks: array, claims: array}> */
    public static function select(iterable $rows): array
    {
        $runs = [];
        foreach ($rows as $row) {
            if ($row instanceof Claim) {
                $row = ['subjectId' => $row->subjectId, 'source' => $row->source, 'runId' => $row->runId,
                    'createdAt' => $row->createdAt->format('c'), 'predicate' => $row->predicate,
                    'value' => $row->value, 'confidence' => $row->confidence, 'basis' => $row->basis];
            }
            if (!is_array($row) || !in_array($row['source'] ?? null, [self::TINY, self::MISTRAL], true)
                || !in_array($row['predicate'] ?? null, self::PREDICATES, true)) {
                continue;
            }
            $id = $row['subjectId'];
            $source = $row['source'];
            $run = (string) ($row['runId'] ?? 'legacy');
            $runs[$id][$source][$run][] = $row;
        }
        $out = [];
        foreach ($runs as $id => $sources) {
            $source = isset($sources[self::MISTRAL]) ? self::MISTRAL : self::TINY;
            $candidates = $sources[$source];
            uksort($candidates, static function ($a, $b) use ($candidates): int {
                $ta = max(array_column($candidates[$a], 'createdAt') ?: ['']);
                $tb = max(array_column($candidates[$b], 'createdAt') ?: ['']);

                return [$tb, $b] <=> [$ta, $a];
            });
            $run = (string) array_key_first($candidates);
            $selected = ['source' => $source, 'runId' => $run, 'text' => '', 'pages' => [], 'blocks' => [], 'claims' => $candidates[$run]];
            foreach ($selected['claims'] as $claim) {
                $value = $claim['value'];
                if (Claim::PRED_OCR_TEXT === $claim['predicate'] && is_string($value)) {
                    $selected['text'] = $value;
                } elseif (self::PAGE === $claim['predicate'] && is_array($value)) {
                    $selected['pages'][] = $value;
                } elseif (self::BLOCK === $claim['predicate'] && is_array($value)) {
                    $selected['blocks'][] = $value;
                }
            }
            usort($selected['blocks'], static fn ($a, $b) => [$a['page'] ?? 0, $a['order'] ?? 0] <=> [$b['page'] ?? 0, $b['order'] ?? 0]);
            $out[$id] = $selected;
        }

        return $out;
    }

    /** Projection compatible with ClaimProjector/ClaimAggregator. Empty OCR remains authoritative. */
    public static function projection(array $selection): array
    {
        $out = [Claim::PRED_HAS_TEXT => ['value' => '' !== trim($selection['text']), 'source' => $selection['source'], 'runId' => $selection['runId'], 'confidence' => null, 'basis' => null], Claim::PRED_OCR_TEXT => ['value' => $selection['text'], 'source' => $selection['source'], 'runId' => $selection['runId'], 'confidence' => null, 'basis' => null]];
        foreach ([self::PAGE, self::BLOCK, self::IMAGE, self::TABLE] as $predicate) {
            $items = array_values(array_filter($selection['claims'], static fn ($c) => $c['predicate'] === $predicate));
            $out[$predicate] = ['value' => array_column($items, 'value'), 'items' => $items, 'source' => $selection['source'], 'confidence' => null, 'basis' => null];
        }

        return $out;
    }
}
