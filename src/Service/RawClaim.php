<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

/**
 * The producer-side shape of a claim — predicate/value/confidence/basis.
 *
 * "Raw" because the persistence-context fields (id, createdAt, scope,
 * subjectType, subjectId, source, runId) are missing — those are stamped by
 * the consumer at ingestion time when calling {@see ClaimIngestor::record()}.
 *
 * Two complementary uses:
 *   1. Wire/transport DTO returned by external observers (e.g. ai-tools'
 *      /v1/responses claim-v1 envelope) — one entry in `claims[]` deserializes
 *      into one RawClaim.
 *   2. Optional embedded field on entities. An app may store the latest
 *      producer output as `Asset::$rawClaims: array<RawClaim>` for cheap
 *      re-reads without joining the claim table. Distinct purpose: rawClaims
 *      is the producer's notebook (overwrite-on-rerun, no attribution),
 *      whereas the claim table holds curated, scope+source+runId-attributed
 *      assertions (append-only).
 *
 * Was previously named `ClaimDraft`. Renamed to drop the "tentative" connotation —
 * the producer's assertion is final-as-the-producer-can-make-it; the consumer
 * doesn't *edit* it, it just *attributes and persists* it.
 */
final class RawClaim
{
    public function __construct(
        public readonly string $predicate,
        public readonly mixed $value,
        public readonly int $confidence = 100,
        public readonly ?string $basis = null,
    ) {
    }
}
