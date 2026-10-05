<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Ulid;
use Survos\FieldBundle\Attribute\EntityMeta;

/**
 * A single machine or human assertion about a subject — the uniform envelope
 * for every predicate a tool emits. Claims are append-only: rerunning a tool
 * deletes the prior run's rows for that (scope, subject, source) and writes
 * fresh ones.
 *
 * Predicate is a compact IRI-ish string chosen by the consumer
 * ('dcterms:title', 'ssai:speculation', 'foaf:Person', ...). The bundle
 * ships no predicate vocabulary — that's an app concern.
 *
 * Scope is an app-defined string that partitions claims for tenancy / user
 * isolation / globality. Examples: 'tenant:rhs', 'user:42', 'global', null.
 * The bundle never interprets it; indexes include it so the app can enforce
 * scope-isolation at query time.
 */
#[EntityMeta(icon: 'mdi:tag-search-outline', group: 'Claims', adminShowCount: false)]
#[ORM\Entity(repositoryClass: ClaimRepository::class)]
#[ORM\Table(name: 'claim')]
#[ORM\Index(fields: ['scope', 'subjectType', 'subjectId', 'predicate'], name: 'idx_claim_scope_subject_pred')]
#[ORM\Index(fields: ['scope', 'source'],                                 name: 'idx_claim_scope_source')]
#[ORM\Index(fields: ['runId'],                                           name: 'idx_claim_run')]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/claims/{id}'),
        new GetCollection(uriTemplate: '/claims'),
    ],
    normalizationContext: ['groups' => ['claim:read']],
)]
#[QueryParameter(key: 'scope', property: 'scope', filter: new ExactFilter())]
#[QueryParameter(key: 'subjectType', property: 'subjectType', filter: new ExactFilter())]
#[QueryParameter(key: 'subjectId', property: 'subjectId', filter: new ExactFilter())]
#[QueryParameter(key: 'predicate', property: 'predicate', filter: new ExactFilter())]
#[QueryParameter(key: 'source', property: 'source', filter: new PartialSearchFilter(caseSensitive: true))]
#[QueryParameter(key: 'order[:property]', properties: ['createdAt', 'confidence', 'predicate', 'source'], filter: new OrderFilter())]
#[QueryParameter(key: 'confidence', property: 'confidence', filter: new RangeFilter())]
class Claim
{
    // ── Subject types.
    // Conventional names any content app reuses. Only the two with clear,
    // non-ambiguous meaning — asset/media overlap, Loc is app-specific.
    public const SUBJECT_IMAGE = 'image';
    public const SUBJECT_ITEM  = 'item';

    // ── Predicates.
    // Standard vocabulary terms stay in their own namespaces (dcterms:, foaf:).
    // Predicates with no natural home in a public vocabulary — agreed-upon
    // names for parsing AI pipeline output — live in the ai: namespace.
    // Downstream exports (Omeka, etc.) map these as needed.
    // Truly app-specific predicates (e.g. 'ssai:civilWarPension') stay in
    // the consuming app — the bundle ships no domain vocabulary of its own.
    //
    // Text-type flags drive OCR/HTR routing. They are non-exclusive: a filled
    // pension form carries typedText + handwrittenText + isForm + isFilledForm.
    public const PRED_HAS_TEXT         = 'ai:hasText';
    public const PRED_TYPED_TEXT       = 'ai:typedText';
    public const PRED_HANDWRITTEN_TEXT = 'ai:handwrittenText';
    public const PRED_IS_FORM          = 'ai:isForm';
    public const PRED_IS_FILLED_FORM   = 'ai:isFilledForm';
    public const PRED_OCR_TEXT         = 'ai:ocrText';
    public const PRED_HTR_TEXT         = 'ai:htrText';
    public const PRED_PRINTED_TEXT     = 'ai:printedText';
    public const PRED_SPECULATION      = 'ai:speculation';
    public const PRED_PIXELS_DONE      = 'ai:pixelsDone';
    public const PRED_HIGH_RES_GOAL    = 'ai:highResGoal';
    public const PRED_HIGH_RES_TARGET  = 'ai:highResTarget';

    /**
     * Semantically-packed ~400-char summary used by search (e.g. Meili's
     * /chat endpoint). Distinct from dcterms:abstract, which is a human-
     * readable prose summary — denseSummary is tuned for retrieval.
     */
    public const PRED_DENSE_SUMMARY    = 'ai:denseSummary';

    /** FOAF standard — a named person. */
    public const PRED_PERSON           = 'foaf:Person';

    /** Named organisation, institution, or corporate body. */
    public const PRED_ORGANISATION     = 'ai:organisation';

    /**
     * Derive a single, human-describable text-type label from a bag of claims for one subject.
     * OCR/HTR routing and narration both want this one answer, not five booleans.
     *
     * Returns one of:
     *   - 'form'        — a pre-printed form (blank or filled). Civil-war pension request, etc.
     *                     isFilledForm wins over plain isForm but they both land here.
     *   - 'combo'       — typed + handwritten together (e.g. the back of a postcard:
     *                     a typed caption paired with a handwritten note).
     *   - 'handwritten' — only handwriting.
     *   - 'typed'       — only typed/printed text.
     *   - 'none'        — no text detected.
     *
     * @param iterable<Claim> $claims
     */
    public static function deriveTextType(iterable $claims): string
    {
        $flags = [
            self::PRED_HAS_TEXT         => false,
            self::PRED_TYPED_TEXT       => false,
            self::PRED_HANDWRITTEN_TEXT => false,
            self::PRED_IS_FORM          => false,
            self::PRED_IS_FILLED_FORM   => false,
        ];
        foreach ($claims as $claim) {
            if (array_key_exists($claim->predicate, $flags) && $claim->value === true) {
                $flags[$claim->predicate] = true;
            }
        }

        if ($flags[self::PRED_IS_FILLED_FORM] || $flags[self::PRED_IS_FORM]) return 'form';
        if ($flags[self::PRED_TYPED_TEXT] && $flags[self::PRED_HANDWRITTEN_TEXT]) return 'combo';
        if ($flags[self::PRED_HANDWRITTEN_TEXT]) return 'handwritten';
        if ($flags[self::PRED_TYPED_TEXT]) return 'typed';
        return 'none';
    }

    #[ORM\Id]
    #[ORM\Column(length: 26)]
    #[Groups(['claim:read'])]
    #[ApiProperty(
        identifier: true,
        description: 'ULID — stable, lexicographically time-sortable identifier.',
        example: '01JH4M8PGQ7X2WC3K5D9B8T2VS',
    )]
    public private(set) string $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['claim:read'])]
    #[ApiProperty(
        description: 'When this claim row was recorded (tool invocation time, not subject creation time).',
        example: '2026-04-19T14:32:08+00:00',
    )]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        /** App-defined partition key. Examples: 'tenant:rhs', 'user:42', 'global', null. */
        #[ORM\Column(length: 64, nullable: true)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'App-defined partition key for isolating claims by tenant, user, or none. Bundle treats it opaquely; the consumer enforces access control.',
            example: 'tenant:rhs',
        )]
        public private(set) ?string $scope,

        /** App-defined subject class key. Examples: 'image', 'item', 'document'. */
        #[ORM\Column(length: 32)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Class of the subject this claim is about. App-defined; no FK is enforced by the bundle.',
            example: 'image',
        )]
        public private(set) string $subjectType,

        /** ULID / UUID / hash identifying the specific subject. */
        #[ORM\Column(length: 64)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Identifier of the subject within its type. Typically a ULID or hash.',
            example: '01J3KXYZ1234567890ABCDEFGH',
        )]
        public private(set) string $subjectId,

        /** Compact IRI: 'dcterms:title', 'ssai:speculation', 'foaf:Person', ... */
        #[ORM\Column(length: 64)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Compact IRI for the assertion type. Consumers pick their vocabulary (DcTerms recommended for standard fields; app-namespaced for extensions).',
            example: 'dcterms:title',
        )]
        public private(set) string $predicate,

        /**
         * Stable tool identifier with version.
         * Examples: 'enrich_from_thumbnail@1.0', 'ocr:tesseract@5',
         * 'human:tac@gmail.com', 'fixture', 'aggregated'.
         */
        #[ORM\Column(length: 128)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Stable tool identifier with version. Humans use "human:{email}"; fixtures use "fixture"; aggregator output uses "aggregated".',
            example: 'enrich_from_thumbnail@1.0',
        )]
        public private(set) string $source,

        /**
         * The asserted value. Scalar for most predicates; structured for
         * places (with coords), speculations (with internal fields), etc.
         */
        #[ORM\Column(type: Types::JSONB)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Asserted value. Scalar for most predicates (string, bool, number); object for structured predicates like places with coordinates.',
            example: 'Welcome to Ocean City',
        )]
        public private(set) mixed $value = null,

        /** 0–100. 100 = human-verified or directly observed. Tool layer normalises here. */
        #[ORM\Column(type: Types::SMALLINT)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Confidence 0–100. 100 = human-verified or directly stated. AI high≈90, medium≈70, low≈50.',
            example: 90,
        )]
        public private(set) int $confidence = 100,

        /** Why the tool asserted this (prompt reasoning, visible evidence, …). */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Why the tool asserted this — visible evidence, prompt reasoning, or "operator verified" for humans. Critical for hallucination review.',
            example: "Printed caption on front of postcard reads 'Welcome to Ocean City'.",
        )]
        public private(set) ?string $basis = null,

        /**
         * Groups every claim produced by one tool invocation.
         * Null for human edits or fixtures that don't simulate a run.
         */
        #[ORM\Column(length: 26, nullable: true)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Groups all claims emitted by one tool invocation. Used for rerun semantics (delete prior run, insert fresh) and for audit trails.',
            example: '01JH4M8PGQ7X2WC3K5D9B8T2VS',
        )]
        public private(set) ?string $runId = null,
    ) {
        $this->id        = (string) new Ulid();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'scope' => $this->scope,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'predicate' => $this->predicate,
            'source' => $this->source,
            'value' => $this->value,
            'confidence' => $this->confidence,
            'basis' => $this->basis,
            'runId' => $this->runId,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        foreach (['subjectType', 'subjectId', 'predicate', 'source'] as $field) {
            if (!\array_key_exists($field, $row) || !\is_string($row[$field]) || $row[$field] === '') {
                throw new \InvalidArgumentException(sprintf('Claim row is missing required field "%s".', $field));
            }
        }

        $claim = new self(
            scope: isset($row['scope']) && \is_string($row['scope']) ? $row['scope'] : null,
            subjectType: $row['subjectType'],
            subjectId: $row['subjectId'],
            predicate: $row['predicate'],
            source: $row['source'],
            value: $row['value'] ?? null,
            confidence: isset($row['confidence']) ? (int) $row['confidence'] : 100,
            basis: isset($row['basis']) && \is_string($row['basis']) ? $row['basis'] : null,
            runId: isset($row['runId']) && \is_string($row['runId']) ? $row['runId'] : null,
        );

        if (isset($row['id']) && \is_string($row['id']) && $row['id'] !== '') {
            $claim->id = $row['id'];
        }

        if (isset($row['createdAt']) && \is_string($row['createdAt']) && $row['createdAt'] !== '') {
            $claim->createdAt = new \DateTimeImmutable($row['createdAt']);
        }

        return $claim;
    }
}
