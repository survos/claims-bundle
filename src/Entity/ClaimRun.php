<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\FieldBundle\Attribute\EntityMeta;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Ulid;

/**
 * Audit record for ONE tool invocation that produced a batch of Claims.
 *
 * Every Claim row carries a `runId` string; this entity is the authoritative
 * sibling of that id with the full call detail: the rendered prompt, which
 * model answered, token counts, wall-clock duration, and any raw response
 * payload we want to keep for debugging / chargeback / replay.
 *
 * Runs are append-only. When a tool reruns for the same (scope, subject,
 * source), ClaimIngestor removes prior claims AND the prior run, then writes
 * fresh ones — so "the current run" for a source is always the one whose
 * claims exist right now.
 */
#[EntityMeta(icon: 'mdi:tag-search-outline', group: 'Claims', adminShowCount: false)]
#[ORM\Entity(repositoryClass: ClaimRunRepository::class)]
#[ORM\Table(name: 'claim_run')]
#[ORM\Index(fields: ['scope', 'subjectType', 'subjectId', 'source'], name: 'idx_claim_run_scope_subject_source')]
#[ORM\Index(fields: ['createdAt'], name: 'idx_claim_run_created')]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/claim-runs/{id}'),
        new GetCollection(uriTemplate: '/claim-runs'),
    ],
    normalizationContext: ['groups' => ['claim_run:read']],
)]
class ClaimRun
{
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    #[Groups(['claim_run:read', 'claim:read'])]
    #[ApiProperty(
        identifier: true,
        description: 'ULID — matches the runId on every Claim this run produced.',
    )]
    public private(set) string $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['claim_run:read', 'claim:read'])]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        /** App-defined partition key (e.g. 'tenant:rhs'). */
        #[ORM\Column(length: 64, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) ?string $scope,

        /** Subject-class key the run targeted (e.g. 'image'). */
        #[ORM\Column(length: 32)]
        #[Groups(['claim_run:read'])]
        public private(set) string $subjectType,

        /** Subject identifier. */
        #[ORM\Column(length: 64)]
        #[Groups(['claim_run:read'])]
        public private(set) string $subjectId,

        /** Tool identifier with version, e.g. 'enrich_from_thumbnail@1.0'. */
        #[ORM\Column(length: 128)]
        #[Groups(['claim_run:read'])]
        public private(set) string $source,

        /** Which model answered (e.g. 'gpt-4o-mini'). Nullable for human/fixture runs. */
        #[ORM\Column(length: 128, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) ?string $model = null,

        /** Rendered prompt text (system + user, concatenated). Large — stored as TEXT. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) ?string $prompt = null,

        /** Raw/summarised response payload for debugging. */
        #[ORM\Column(type: Types::JSONB, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) mixed $response = null,

        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) ?int $inputTokens = null,

        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) ?int $outputTokens = null,

        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) ?int $imageTokens = null,

        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        #[Groups(['claim_run:read'])]
        public private(set) ?int $durationMs = null,

        /** How many claims this run emitted. Convenience — keeps us from joining at list time. */
        #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
        #[Groups(['claim_run:read'])]
        public private(set) int $claimCount = 0,

        /* Optional ULID to pin the id; when null, a fresh one is generated. */
        ?string $id = null,
    ) {
        $this->id = $id ?? (string) new Ulid();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setClaimCount(int $n): void
    {
        $this->claimCount = $n;
    }
}
