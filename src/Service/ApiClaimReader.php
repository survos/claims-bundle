<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Survos\ClaimsBundle\Exception\ClaimsUnavailableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * {@see ClaimReaderInterface} over mediary's HTTP API instead of a direct Postgres connection.
 *
 * Why this exists: a reader app (zm, a public site) displays claims it never writes, yet the DBAL
 * reader made it hold a Postgres DSN — meaning network reachability to the database, a readonly
 * role to provision, and a credential to rotate, in every app. Over HTTP the app holds a URL and a
 * token, and mediary remains the only process that touches the claims database.
 *
 * Returns the SAME row shape as {@see ClaimReader} (the `claim` table's columns, `value` decoded),
 * so swapping transports is a config change and callers cannot tell the difference.
 *
 * Failure policy: a read failure throws {@see ClaimsUnavailableException}, it does not return empty.
 *
 * This started as "return empty so a mediary hiccup degrades a page instead of 500ing it", which is
 * the right instinct for a display panel and the wrong one for everything else — because `[]` also
 * means "this scope genuinely has no claims", and nothing downstream can tell the two apart. The
 * only consumer of this interface is {@see ClaimsVaultWriter}, which PERSISTS what it reads into the
 * vault claims.jsonl that enrich then treats as truth. Under the old policy an expired token or a
 * momentarily unreachable mediary rewrote that file as empty and marked it complete: enrich folded
 * zero claims, the folio was rebuilt without them, and because _folio shadows norm on mtime it
 * stayed that way until somebody re-ran enrich by hand. Worse, the guard written for exactly this
 * (dataset:assemble's "enriching against the existing claims.jsonl" catch) never fired, because
 * nothing threw.
 *
 * Displaying callers get the graceful behaviour by catching ClaimsUnavailableException, which is a
 * decision they can make locally. Persisting callers cannot silently inherit it.
 */
final class ApiClaimReader implements ClaimReaderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $baseUri = null,
        private readonly ?string $token = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->baseUri && '' !== $this->baseUri;
    }

    public function forSubjects(array $subjectIds, ?string $scope = null): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        return $this->rows('/api/claim-store/subjects', [
            'ids' => array_values($subjectIds),
            'scope' => $scope,
        ]);
    }

    public function manifestedForSubjects(array $subjectIds, ?string $scope = null): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        // Manifesting is "first row per (subject,predicate) from a created_at DESC ordering", which
        // the DBAL reader does in PHP over forSubjects(). Doing it server-side matters here: it is
        // the difference between transferring every historical claim and transferring only the
        // winners, and revision history is exactly the kind of data that grows without bound.
        return $this->rows('/api/claim-store/subjects', [
            'ids' => array_values($subjectIds),
            'scope' => $scope,
            'manifested' => 1,
        ]);
    }

    public function forScope(string $scope, array $excludeSources = []): array
    {
        return $this->rows('/api/claim-store/scope', ['scope' => $scope, 'excludeSources' => $excludeSources]);
    }

    public function runsForScope(string $scope, array $excludeSources = []): array
    {
        return $this->rows('/api/claim-store/runs', ['scope' => $scope, 'excludeSources' => $excludeSources]);
    }

    public function countForSubject(string $subjectId, ?string $scope = null): int
    {
        $payload = $this->request('/api/claim-store/count', [
            'id' => $subjectId,
            'scope' => $scope,
        ]);

        return (int) ($payload['count'] ?? 0);
    }

    /**
     * @param array<string,mixed> $query
     * @return list<array<string,mixed>>
     */
    private function rows(string $path, array $query): array
    {
        $payload = $this->request($path, $query);
        $rows = $payload['rows'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function request(string $path, array $query): array
    {
        // Unconfigured is a failure too, not an empty result set. Callers that want to tolerate an
        // unconfigured reader have isAvailable() to check first; silently answering "no claims"
        // would make a missing base_uri indistinguishable from a scope nobody has enriched yet.
        if (!$this->isAvailable()) {
            throw new ClaimsUnavailableException('Claims API base_uri is not configured (survos_claims.api.base_uri).');
        }

        // Drop nulls so an unset $scope means "no filter" rather than "scope must be null" —
        // matching the DBAL reader, which omits the WHERE clause entirely.
        $query = array_filter($query, static fn ($v) => null !== $v && '' !== $v);

        $headers = ['Accept' => 'application/json'];
        if (null !== $this->token && '' !== $this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUri, '/') . $path, [
                'query' => $query,
                'headers' => $headers,
            ]);

            $status = $response->getStatusCode();
            if (200 !== $status) {
                $this->logger->warning('claims API returned HTTP {status} for {path}', [
                    'status' => $status,
                    'path' => $path,
                ]);

                throw new ClaimsUnavailableException(sprintf('Claims API returned HTTP %d for %s.', $status, $path));
            }

            return $response->toArray(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            // HttpExceptionInterface is aliased from HttpClient's ROOT ExceptionInterface, so this
            // covers transport failures (connection refused, DNS, timeout) as well as HTTP ones.
            $this->logger->warning('claims API request failed for {path}: {message}', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            throw new ClaimsUnavailableException(sprintf('Claims API request failed for %s: %s', $path, $e->getMessage()), 0, $e);
        }
    }
}
