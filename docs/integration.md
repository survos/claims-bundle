# Integration guide

Wiring the bundle into a Symfony app, end-to-end.

## 1. Install

```bash
composer require survos/claims-bundle
```

Flex registers the bundle in `config/bundles.php` automatically.

The bundle prepends its Doctrine ORM mapping automatically, so the
`Claim` entity is discovered without extra app configuration.

## 2. Configure list predicates

Tell the aggregator which predicates produce lists. Everything else is
treated as a scalar.

```yaml
# config/packages/survos_claims.yaml
survos_claims:
    list_predicates:
        - dcterms:subject    # keywords
        - dcterms:spatial    # places
        - foaf:Person
        - ssai:speculation
```

Missing entries default to scalar — safe but might collapse lists, so
register every list-valued predicate the app uses.

## 3. Create the table

Dev:

```bash
bin/console doctrine:schema:update --force
```

Prod:

```bash
bin/console make:migration
bin/console doctrine:migrations:migrate
```

## 3b. Shared claims DB across apps (one store, many apps)

The setup above keeps the `claim` table in the app's own DB. When several apps must share
claims — **writers** producing them (enrich pipeline, mediary) and **readers** displaying them
(a public site) — point them all at one central `claims` Postgres. **mediary owns the schema;**
the others just connect.

**Writer apps** (e.g. md, mediary) target a dedicated `claims` EM instead of the app DB:

```yaml
# config/packages/survos_claims.yaml
survos_claims:
    entity_manager: claims          # the Claim/ClaimRun mapping + ClaimIngestor use this EM
```
```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            claims:
                url: '%env(resolve:CLAIMS_DATABASE_URL)%'
    orm:
        entity_managers:
            claims:
                connection: claims  # NO `mappings:` — the bundle attaches them when entity_manager: claims
```
```dotenv
# .env — default to the app DB so local/dev never breaks; prod overrides to the central DB.
CLAIMS_DATABASE_URL=${DATABASE_URL}
```

`ClaimIngestor` writes via that EM. **Flush with `$ingestor->flush()`, never your own
`EntityManager`** — with a separate EM, flushing the default one silently never commits the claims.

### Readers: HTTP API instead of a database DSN (preferred)

A reader app displays claims it never writes, so making it hold a Postgres DSN means network
reachability to the database, a readonly role to provision, and a credential to rotate — in every
app, including public-facing ones. Point it at mediary's HTTP API instead:

```yaml
# config/packages/survos_claims.yaml  (e.g. zm)
survos_claims:
    reader_only: true
    reader: api
    api:
        base_uri: '%env(CLAIMS_API_BASE_URI)%'   # https://mediary.survos.com
        token:    '%env(CLAIMS_API_TOKEN)%'      # must match mediary's CLAIMS_API_TOKEN
```

No `CLAIMS_DATABASE_URL`, no `MEDIARY_RO_DATABASE_URL`, no doctrine `claims` connection.

**Type-hint `ClaimReaderInterface`, not `ClaimReader`.** The interface is aliased to whichever
transport is configured; the concrete `ClaimReader` remains DBAL, so an existing concrete
type-hint keeps getting a database connection and will silently ignore `reader: api`.

Server side (mediary) exposes `GET /api/claim-store/{subjects,scope,runs,count}`, Bearer-authed
against `CLAIMS_API_TOKEN`. Note the path is `/api/claim-store/`, not `/api/claims/` — the latter
is API Platform's `Claim` resource, and `/api/claims/runs` would match it as `{id}`. The
firewall lists the prefix PUBLIC_ACCESS; the controller does the real auth and returns 503 when
the token is unset, so an unconfigured deploy fails closed.

Both transports return the same rows. Verified against real data (scope `mediary`): `forSubjects`
49/49, `manifestedForSubjects` 39/39, `runsForScope` 135/135, `countForSubject` 15/15 — byte
identical JSON.

### Failure contract

**An empty array means "there are no claims". A store that could not be read throws
`ClaimsUnavailableException`.** Both transports obey this: the API reader throws on any transport
error, non-200, or unconfigured `base_uri`; the DBAL reader throws the same type when no connection
is wired (it extends `RuntimeException`, so existing catches are unaffected).

This started out the other way round — the API reader logged and returned empty, on the reasoning
that claims are supplementary display data and mediary being down should degrade a page rather than
500 it. The instinct is right for a display panel and wrong as a transport-level default, because
`[]` is also the legitimate answer for a scope with no claims, and nothing downstream can tell them
apart. `ClaimsVaultWriter` is the only consumer of the interface, and it *persists* what it reads
into the vault `claims.jsonl` that enrich then treats as truth. Under the old policy an expired
token or a momentary outage rewrote that file as empty and marked it complete: enrich folded zero
claims, the folio was rebuilt without them, and because `_folio` shadows `norm` on mtime it stayed
that way until someone re-ran enrich by hand. The guard written for exactly this case —
`dataset:assemble`'s "enriching against the existing claims.jsonl" catch — never fired, because
nothing threw.

If you want a consumer to degrade gracefully, catch it there:

```php
try {
    $claims = $reader->forSubjects($ids, $scope);
} catch (ClaimsUnavailableException) {
    $claims = [];   // an explicit local decision, not one inherited from the transport
}
```

`ClaimsVaultWriter` additionally reads *before* opening its writer, since `JsonlWriter::open()`
truncates on open — so even an unexpected failure mid-fetch leaves the previous vault file intact
rather than replacing it with an empty one.

### Imported claims are not read back

`Claim::SOURCE_IMPORT` (`@import`) marks claims a client sent from its own metadata: mediary's
`/batch` records each item's title, date and place that way, so AI tasks can use them as context.
The client already holds that data, so `ClaimsVaultWriter::write()` leaves them out by default
(`$excludeSources = [Claim::SOURCE_IMPORT]`); the vault `claims.jsonl` then carries only what the
store learned on its own (AI and human claims). Folding the echoes back only re-ingested the
client's own fields: on mus/cleveland 180,076 of 180,262 claims were `@import`, about 90 s of a
103 s folio build, which dropped to 10.7 s without them.

`forScope()` and `runsForScope()` take the same `$excludeSources` list on both transports; over the
API it is `GET /api/claim-store/scope?scope=…&excludeSources[]=@import`. A server that predates the
parameter ignores it and returns the whole scope, so an older mediary degrades to the previous
behaviour rather than failing. Pass `excludeSources: []` to `write()`, or `claims:fetch --include-imported`, to fetch everything.

**Reader apps over DBAL** (legacy) don't map the entity — they read the central store over DBAL:

```yaml
survos_claims:
    reader_only: true               # no claim table, no writer services; ClaimReader only
```
```dotenv
MEDIARY_RO_DATABASE_URL=postgresql://…ro…@host:5432/claims   # ClaimReader uses this
```

**Create the central DB + schema once.** Two flat tables (`claim`, `claim_run`); the bundle owns
the entity definitions so there are no per-EM migration files. Use the idempotent script (also
creates the database if it's missing):

```bash
# in a writer's container, where CLAIMS_DATABASE_URL is set (dokku config:set):
dokku run mus php bin/init-claims-schema.php
# or anywhere, with an explicit DSN:
php bin/init-claims-schema.php 'postgresql://USER:PASS@HOST:5432/claims'
```
Equivalently from a writer app: `bin/console doctrine:schema:create --em=claims`.

> The `entity_manager` option defaults to `'default'` (the single-DB setup above), so existing
> single-app consumers are unaffected. `mediary` is the canonical owner/writer; `md` writes
> AI/OCR claims; `zm` reads — all against the same `claims` DB.

## 4. Pick a scope convention

The bundle's `scope` column is nullable and app-interpreted. Pick one
convention and apply it everywhere:

- **Tenanted app** — use `'tenant:' . $tenant->code`.
- **User-scoped app** — use `'user:' . $user->getId()`.
- **Global** — use `'global'` or `null`.

Enforce scope at every call site. The bundle only stores; access control
is an app concern.

If the consuming app cascades deletes (e.g. deleting a Tenant should wipe
its claims), register a Doctrine listener:

```php
#[AsDoctrineListener(event: Events::preRemove)]
final class TenantClaimsCleanupListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Tenant) {
            return;
        }
        $this->em->createQuery('DELETE Survos\ClaimsBundle\Entity\Claim c WHERE c.scope = :s')
            ->setParameter('s', 'tenant:' . $entity->code)
            ->execute();
    }
}
```

The bundle does not ship this because it has no opinion on what a "tenant"
is in the consuming app.

## 5. Write claims from your tool

Each machine or human tool that produces claims:

```php
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\DataContracts\Vocabulary\DcTerms;

final class EnrichFromThumbnailTool
{
    public function __construct(
        private readonly ClaimIngestor $ingestor,
        private readonly EntityManagerInterface $em,
    ) {}

    public function runOn(Image $image, string $scope): void
    {
        $result = $this->callLlm($image);  // returns a typed DTO

        $rawClaims = [
            new RawClaim(DcTerms::TITLE->value, $result->title, 0.9,
                basis: $result->titleBasis),
            new RawClaim(DcTerms::DESCRIPTION->value, $result->description, 0.8),
            new RawClaim(DcTerms::TYPE->value, $result->contentType, 0.95),
        ];

        foreach ($result->keywords as $kw) {
            $rawClaims[] = new RawClaim(
                predicate:  DcTerms::SUBJECT->value,
                value:      $kw['term'],
                confidence: $this->mapConfidence($kw['confidence']),
                basis:      $kw['basis'],
            );
        }

        $this->ingestor->record(
            scope:       $scope,
            subjectType: 'image',
            subjectId:   $image->getId(),
            source:      'enrich_from_thumbnail@1.0',
            rawClaims:   $rawClaims,
        );
        $this->em->flush();
    }

    private function mapConfidence(string $level): float
    {
        return match ($level) {
            'high'   => 0.9,
            'medium' => 0.6,
            'low'    => 0.3,
            default  => 0.5,
        };
    }
}
```

## 6. Read the aggregated view

Anywhere you previously read the AI blob, read the aggregator instead:

```php
$view = $aggregator->aggregate('image', $image->getId(), $scope);
$title       = $view['dcterms:title']['value']       ?? null;
$keywords    = $view['dcterms:subject']['value']     ?? [];  // list
$contentType = $view['dcterms:type']['value']        ?? null;
```

For search indexing, the list of contributing sources per claim is
available under `$view[$pred]['items']` — useful for tier-based indexing
(only high-confidence terms in the main index, medium/low as suggestions).

## 7. Export / import

```bash
bin/console claims:export --scope=tenant:rhs > rhs.jsonl
bin/console claims:import --scope=tenant:rhs < rhs.jsonl
```

Shell redirection is the default workflow here on purpose, since it keeps
the commands composable for quick inspection and ad hoc transforms.

If you want file-based JSONL I/O instead of shell redirection, use:

```bash
bin/console claims:export --scope=tenant:rhs --output=rhs.jsonl.gz
bin/console claims:import --scope=tenant:rhs --input=rhs.jsonl.gz
```

Those code paths use `survos/jsonl-bundle`'s `JsonlWriter` and
`JsonlReader`. The plain stdin/stdout path keeps minimal NDJSON handling
for shell use. Import skips existing claim ids by default so rerunning
the same backup is safe.

## 8. Human corrections

No new API — just a different `source`:

```php
$this->ingestor->record(
    scope:       $scope,
    subjectType: 'image',
    subjectId:   $image->getId(),
    source:      'human:' . $user->getEmail(),
    rawClaims:   [new RawClaim(DcTerms::TITLE->value, $corrected, 1.0,
        basis: 'Operator verified.')],
);
```

The aggregator treats this claim like any other. Give humans a higher
default confidence and the aggregator will pick their claim when it
disagrees with the AI.
