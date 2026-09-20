<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\DataContracts\Vocabulary\DcTerms;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\MuseumVocab;

/**
 * Flattens a ClaimAggregator result onto a normalized JSONL row.
 *
 * Scalar predicates: winning value replaces the corresponding row field.
 * List predicates:   items[] are extracted into arrays keyed by MuseumVocab
 *                    short codes (per, pla, obj, org) — the same codes the
 *                    normalizer uses for entity JSONL files (per.jsonl, etc.).
 */
final class ClaimProjector
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, array{value: mixed, confidence: int, basis: ?string, source: string, items?: list<array{value:mixed}>}> $claims
     * @return array<string, mixed>
     */
    public function project(array $row, array $claims): array
    {
        foreach (self::SCALAR_MAP as $predicate => $field) {
            if (isset($claims[$predicate])) {
                $row[$field] = $claims[$predicate]['value'];
            }
        }

        foreach (self::LIST_MAP as $predicate => $field) {
            if (isset($claims[$predicate]['items'])) {
                $row[$field] = array_column($claims[$predicate]['items'], 'value');
            }
            // The raw predicate (e.g. dcterms:subject) is now handled — it's projected onto the
            // relation core ($field). Drop it so it doesn't fall through to folio extras, which should
            // only carry genuinely unmapped/noise keys. (Scalar predicates are consumed by the DTO
            // #[Map] instead, so they don't dangle.)
            unset($row[$predicate]);
        }

        return $row;
    }

    private const SCALAR_MAP = [
        DcTerms::TITLE->value       => ItemField::TITLE,
        DcTerms::DESCRIPTION->value => ItemField::DESCRIPTION,
        DcTerms::ABSTRACT->value    => ItemField::DESCRIPTION,
        ItemField::DENSE_SUMMARY    => ItemField::DENSE_SUMMARY,
        DcTerms::DATE->value        => ItemField::DATE,
        DcTerms::LANGUAGE->value    => ItemField::LANGUAGE,
        DcTerms::TYPE->value        => ItemField::CONTENT_TYPE,
        // No ItemField const for this one — 'ocrText' is BaseItemDto::$ocrText's literal property
        // name (see its docblock: "folded in from the vault's claims.jsonl (ai:ocrText)"). Without
        // this line the fold-in that comment promises never actually happens — DatasetAiCommand's
        // ocrDocument() and YoutubeRawCommand both record this claim expecting enrich to carry it
        // onto the item, but SCALAR_MAP had no entry for it until now.
        Claim::PRED_OCR_TEXT        => 'ocrText',
        // merit_score (ai-workflow-bundle): 0-100 per criterion onto BaseItemDto's numeric merit
        // fields, so the folio grid can facet them as range sliders. Literal predicates because
        // this bundle does not depend on ai-workflow-bundle, where the task defines them.
        'merit:overall'                 => 'meritOverall',
        'merit:action'                  => 'meritAction',
        'merit:culturalPractice'        => 'meritCulturalPractice',
        'merit:historicalSignificance'  => 'meritHistoricalSignificance',
        'merit:captivates'              => 'meritCaptivates',
        'merit:inTheAct'                => 'meritInTheAct',
        'merit:quality'                 => 'meritQuality',
        'merit:originality'             => 'meritOriginality',
    ];

    private const LIST_MAP = [
        DcTerms::SUBJECT->value    => MuseumVocab::SUBJECT,        // obj
        DcTerms::SPATIAL->value    => MuseumVocab::PLACE,          // pla
        Claim::PRED_PERSON         => MuseumVocab::PERSON,         // per
        Claim::PRED_ORGANISATION   => MuseumVocab::ORGANISATION,   // org
    ];
}
