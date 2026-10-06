<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

/**
 * Read access to the central claims store, independent of HOW that store is reached.
 *
 * Two implementations:
 *   {@see ClaimReader}     direct Postgres over DBAL — requires CLAIMS_DATABASE_URL in the app.
 *   {@see ApiClaimReader}  HTTP against mediary — requires a base URI and a token.
 *
 * The interface exists so a consumer never learns which one it got. Reader apps used to need a
 * Postgres DSN (and therefore network access to the DB, a role to manage, and a credential to
 * rotate) purely to *display* claims they never write. Over the API they need a URL and a token,
 * and mediary stays the only process with database access.
 *
 * Row shape is the `claim` table's columns as associative arrays — scope, subject_type,
 * subject_id, predicate, source, value, confidence, basis, run_id, created_at — with `value`
 * already JSON-decoded. Both implementations MUST return that same shape, so switching transport
 * is a config change and nothing else.
 *
 * FAILURE CONTRACT: an empty array means "there are no claims". A store that could not be read
 * throws {@see \Survos\ClaimsBundle\Exception\ClaimsUnavailableException} — it never returns empty.
 *
 * This is load-bearing, not pedantry. The interface's consumers include
 * {@see ClaimsVaultWriter}, which persists what it reads into the vault claims.jsonl that enrich
 * subsequently treats as truth; if an unreachable store answered `[]`, a transport blip would
 * quietly become "this dataset has no claims" and survive every later build. A consumer that
 * genuinely wants to degrade — a display panel, say — catches the exception, which is a decision
 * it makes explicitly rather than one inherited from the transport.
 */
interface ClaimReaderInterface
{
    /**
     * True when the backing store is actually reachable-in-principle (connection wired / base URI
     * configured). Callers use this to degrade gracefully rather than fatal — claims are
     * supplementary display data in most consumers.
     */
    public function isAvailable(): bool;

    /**
     * All claims for the given subjects (media ids), newest first.
     *
     * @param list<string> $subjectIds
     * @return list<array<string,mixed>>
     */
    public function forSubjects(array $subjectIds, ?string $scope = null): array;

    /**
     * The winning (manifested) claim per (subject, predicate) — the most recent value across
     * sources. This is what enrich folds inline into Item/Page records.
     *
     * @param list<string> $subjectIds
     * @return list<array<string,mixed>>
     */
    public function manifestedForSubjects(array $subjectIds, ?string $scope = null): array;

    /**
     * Every claim for a whole scope (dataset), ordered newest-first per subject.
     *
     * @param list<string> $excludeSources sources to leave out, e.g. [Claim::SOURCE_IMPORT]
     * @return list<array<string,mixed>>
     */
    public function forScope(string $scope, array $excludeSources = []): array;

    /**
     * Every claim_run for a whole scope (dataset).
     *
     * @param list<string> $excludeSources sources to leave out, e.g. [Claim::SOURCE_IMPORT]
     * @return list<array<string,mixed>>
     */
    public function runsForScope(string $scope, array $excludeSources = []): array;

    /** Number of claims for a single subject (media id). */
    public function countForSubject(string $subjectId, ?string $scope = null): int;
}
