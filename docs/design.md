# Design

## The problem this bundle solves

AI-generated metadata is usually dumped into a JSON blob on the subject:

```json
{
  "title": "Welcome to Ocean City",
  "keywords": ["boardwalk", "seaside"],
  "date_hint": "1940s"
}
```

This shape hides a lot of important information:

- **How confident was the AI?** Not captured.
- **Why did it say that?** Not captured.
- **Which tool produced it?** Usually captured by convention (`aiResults[task]`)
  but the task identifier is rarely versioned.
- **What happens when a second tool run disagrees?** The second overwrites
  the first, and the disagreement — which is real signal — is lost.
- **Can a human override with a reason?** Only if the schema was planned
  for it, usually with another field.
- **How do you back this up portably?** The blob shape evolves, so imports
  break silently after a schema change.

## The claim model

Replace the blob with a sequence of rows. Each row is one assertion:

```
Claim {
  id:         ULID            // time-sortable row identity
  scope:      ?string         // 'tenant:rhs', 'user:42', 'global', null
  subjectType: string         // 'image', 'item', 'document'
  subjectId:  string          // ULID / UUID / hash of subject
  predicate:  string          // 'dcterms:title', 'ssai:speculation', ...
  value:      mixed           // scalar or JSON object
  confidence: float           // 0.0–1.0
  basis:      ?string         // "why" the tool said this
  source:     string          // 'enrich_from_thumbnail@1.0', 'human:tac@...'
  runId:      ?string         // groups claims from one invocation
  createdAt:  DateTimeImmutable
}
```

### What each field gives you

- **confidence** makes uncertain AI output first-class. You can filter to
  high-confidence claims for indexing, or show low-confidence claims as
  "suggestions" in the UI.
- **basis** is the anti-hallucination field. When a human reviews a claim
  they need to know what the AI was reacting to. "Printed caption reads X"
  is actionable; an unexplained assertion isn't.
- **source** identifies the producing tool *and its version*. When you
  deploy a better prompt, the new version's claims don't conflict with
  the old ones — they coexist until the aggregator picks a winner.
- **runId** groups every claim from one invocation. Useful for audit
  ("show me everything this prompt produced") and rerun semantics (below).
- **scope** is the bundle's tenancy opt-in. The bundle treats it as opaque;
  the consuming app enforces access control at query time.

## Predicate vocabulary

The bundle ships **no** predicate constants. Consumers pick their own:

- Prefer a standard vocabulary (Dublin Core's `dcterms:*`, FOAF's `foaf:*`,
  schema.org, ...).
- Introduce app-namespaced predicates (`ssai:speculation`) only for
  concepts that have no standard term.
- Keep them short and stable — they end up in query filters and index keys.

Predicates live as strings in the DB so a consumer can add new ones without
a migration. The aggregator doesn't interpret the predicate name — it only
cares whether it's registered as a list predicate (keywords, places, …)
or treated as scalar (title, description, …).

## Append-only, rerun-safe

Claims are never mutated. When a tool reruns:

1. `ClaimIngestor::record($scope, $subjectType, $subjectId, $source, $drafts)`
2. deletes all prior rows with the same `(scope, subjectType, subjectId, source)`
3. persists the new raw claims under a fresh `runId`.

**Unchanged re-assertions are a no-op.** If the incoming claims are exactly the stored set (same
predicate, value, confidence and basis for every claim, compared order-independently, with jsonb
key order normalised), and the call carries no `RunMeta` and no caller-chosen `runId`, `record()`
and `recordBatch()` return the existing `ClaimRun` and write nothing: no deletes, no inserts, no
JSONL lines. This is the common case for imported metadata: mediary's `/batch` receives each
record's `@import` claims on every dispatch, and delete-then-insert made that about ten row writes
per record per pass (~600 ms per 100-record batch on mus/cleveland; ~5 ms after). A call with
`RunMeta` (an AI run, with its model, prompt and tokens) is a new run by definition and is always
recorded, even when its claims happen to match.

This means you never have to worry about "how do I update the existing
claim?" — you don't, you rerun. Two versions of the same tool (v1 and
v1.1) count as different sources, so their claims coexist until you
deprecate v1.

Human corrections are just another source:

```php
$rawClaim = new RawClaim(DcTerms::TITLE->value, 'Welcome to Ocean City, MD', 1.0,
    basis: 'Operator verified against original postcard.');

$ingestor->record($scope, 'image', $imageId,
    source: 'human:tac@example.com',
    rawClaims: [$rawClaim]);
```

The aggregator then weighs human source vs AI source per its policy (usually
human wins).

## Aggregation

`ClaimAggregator::aggregate($subjectType, $subjectId, $scope)` returns a
map of predicate → best-guess claim.

- **Scalar predicates** (title, description, content_type, …): one winner.
  Default policy = max confidence wins, most-recent tiebreak.
- **List predicates** (keywords, places, people, …): union. Default policy =
  dedup on value, confidence = max across contributing sources.

Which predicates are list-valued is app-configured
(`survos_claims.list_predicates`) since the bundle doesn't own the
vocabulary.

The aggregator's output is itself claim-shaped (same `{value, confidence,
basis, source}` envelope) so a single UI component can render either a
raw claim or an aggregated one.

## JSONL round-trip

One claim = one line of JSONL. No schema assembly, no shape changes over
time — if the Claim entity grows a field, old JSONL imports still work
(unknown fields get default values; missing fields degrade gracefully).

This is why the bundle is storage-layer-only. The lifecycle is:

```
tool runs → RawClaim[] → ClaimIngestor → claim rows
     ↑                                          ↓
     └── on rerun: delete by source ──────────── ↓
                                                 ↓
                              ClaimAggregator reads → best-guess view
                                                 ↓
                                       (Meili / UI / API consume)

                    bin/console claims:export > tenant.jsonl
                    bin/console claims:import < tenant.jsonl
                    bin/console claims:export --output=tenant.jsonl.gz
                    bin/console claims:import --input=tenant.jsonl.gz
```

The bundle intentionally supports both modes:

- shell redirection for quick streaming and inspection
- `survos/jsonl-bundle` file I/O for explicit `.jsonl` / `.jsonl.gz` paths

## Why not just extend the old blob?

1. Evolvability. Adding a field to the blob requires migrating every
   existing row. Adding a field to Claim is a single schema change that
   doesn't touch rows.
2. Provenance. The blob makes "who said this?" hard and "why?" harder.
   Every claim carries its own provenance.
3. Conflict is information. Two tools disagreeing about a date is a
   signal the human cataloguer wants to see, not a bug to hide.
4. Human overrides compose naturally. No separate "override" table.
5. Portability. JSONL of claims is a stable exchange format regardless
   of how the projection/aggregation evolves.
