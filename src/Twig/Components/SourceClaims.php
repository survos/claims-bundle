<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Twig\Components;

use Survos\DataContracts\Dto\Item\BaseItemDto;
use Survos\DataContracts\Metadata\ContentType;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders source metadata (sourceMeta / ctx) for an Asset.
 *
 * Usage:
 *   <twig:SourceClaims :ctx="asset.sourceMeta" />
 *
 * The field schema comes from ContentType constants and BaseItemDto property names —
 * no hard-coded field lists in the template.
 *
 * Fields are ordered: core DC → type-specific → remainder (catch-all).
 */
#[AsTwigComponent('SourceClaims', template: '@SurvosClaims/components/SourceClaims.html.twig')]
final class SourceClaims
{
    /** The sourceMeta array from the Asset */
    public array $ctx = [];

    /** Whether to show a catch-all for unknown fields */
    public bool $showUnknown = true;

    /**
     * Core DC fields shown for every content type.
     * key => [label, display_type]  where display_type is 'text'|'badges'|'url'|'bool'.
     */
    private const CORE_FIELDS = [
        'dcterms:title' => ['Title',       'text'],
        'dcterms:description' => ['Description', 'text'],
        'dcterms:date' => ['Date',         'text'],
        'dcterms:creator' => ['Creator',      'badges'],
        'dcterms:subject' => ['Subject',      'badges'],
        'dcterms:isPartOf' => ['Collection',   'badges'],
        'dcterms:publisher' => ['Institution',  'text'],
        'dcterms:language' => ['Language',     'text'],
        'dcterms:extent' => ['Extent',       'text'],
        'dcterms:rights' => ['Rights',       'text'],
        'dcterms:license' => ['License',      'url'],
        'dcterms:accessRights' => ['Reuse',        'text'],
        'dcterms:source' => ['Source URL',   'url'],
    ];

    /**
     * Type-specific extra fields keyed by content type slug.
     * Merged with CORE_FIELDS for display.
     */
    private const TYPE_FIELDS = [
        ContentType::PHOTOGRAPH => [
            'dcterms:format' => ['Process/Technique', 'text'],
        ],
        ContentType::POSTCARD => [
            'dcterms:format' => ['Process/Technique', 'text'],
            'postmark_date' => ['Postmark',           'text'],
        ],
        ContentType::NEGATIVE => [
            'dcterms:format' => ['Film Type',  'text'],
        ],
        ContentType::MAP => [
            'scale' => ['Scale',       'text'],
            'projection' => ['Projection',  'text'],
        ],
        ContentType::NEWSPAPER => [
            'volume' => ['Volume',  'text'],
            'issue_number' => ['Issue',   'text'],
        ],
        ContentType::PERIODICAL => [
            'volume' => ['Volume',  'text'],
            'issue_number' => ['Issue',   'text'],
        ],
        ContentType::MANUSCRIPT => [
            'has_transcription' => ['Transcription', 'bool'],
        ],
        ContentType::CORRESPONDENCE => [
            'has_transcription' => ['Transcription', 'bool'],
        ],
        ContentType::OBJECT => [
            'material' => ['Material',   'text'],
            'technique' => ['Technique',  'text'],
            'donor' => ['Donor',      'text'],
        ],
    ];

    /** Keys never shown in the catch-all (internal/technical) */
    private const HIDDEN_KEYS = [
        'content_type', 'aggregator', 'source_id',
        'iiif_base', 'iiif_manifest', 'iiif_info', 'iiif_image',
        'thumbnail_url', 'image_url', 'media_id',
        'rights', 'license_uri', 'reuse_allowed',  // legacy aliases handled above
    ];

    /**
     * Convert the raw sourceMeta blob to a typed DTO.
     */
    public function dto(): ?object
    {
        if (!$this->ctx) {
            return null;
        }

        return BaseItemDto::fromSourceMeta($this->ctx);
    }

    public function contentType(): ?string
    {
        return $this->ctx['content_type'] ?? null;
    }

    public function contentTypeLabel(): string
    {
        $ct = $this->contentType();

        return $ct ? ucfirst($ct) : '';
    }

    public function contentTypeUri(): ?string
    {
        $ct = $this->contentType();

        return $ct ? ContentType::uri($ct) : null;
    }

    /**
     * Ordered field definitions for this content type.
     * Returns array of [key, label, type, value] — only non-empty values.
     */
    public function fields(): array
    {
        $ct = $this->contentType();
        $schema = self::CORE_FIELDS;

        // Merge type-specific fields
        if ($ct && isset(self::TYPE_FIELDS[$ct])) {
            $schema = array_merge($schema, self::TYPE_FIELDS[$ct]);
        }

        $rows = [];
        $handled = array_keys($schema);

        foreach ($schema as $key => [$label, $type]) {
            $value = $this->resolve($key);
            if (null !== $value && '' !== $value && [] !== $value) {
                $rows[] = compact('key', 'label', 'type', 'value');
            }
        }

        return $rows;
    }

    /**
     * Extra fields not in the schema — shown only when showUnknown=true.
     */
    public function extraFields(): array
    {
        if (!$this->showUnknown) {
            return [];
        }

        $ct = $this->contentType();
        $schema = self::CORE_FIELDS;
        if ($ct && isset(self::TYPE_FIELDS[$ct])) {
            $schema = array_merge($schema, self::TYPE_FIELDS[$ct]);
        }
        $handled = array_merge(array_keys($schema), self::HIDDEN_KEYS);

        $rows = [];
        foreach ($this->ctx as $key => $value) {
            if (in_array($key, $handled, true)) {
                continue;
            }
            if (null === $value || '' === $value || [] === $value) {
                continue;
            }
            $label = ucwords(str_replace(['dcterms:', 'schema:', '_'], ['', '', ' '], $key));
            $type = is_array($value) ? 'badges' : (filter_var($value, FILTER_VALIDATE_URL) ? 'url' : 'text');
            $rows[] = compact('key', 'label', 'type', 'value');
        }

        return $rows;
    }

    private function resolve(string $key): mixed
    {
        // Primary key
        if (isset($this->ctx[$key])) {
            return $this->ctx[$key];
        }

        // Legacy aliases
        return match ($key) {
            'dcterms:rights' => $this->ctx['rights'] ?? null,
            'dcterms:license' => $this->ctx['license_uri'] ?? $this->ctx['license'] ?? null,
            'dcterms:accessRights' => $this->ctx['reuse_allowed'] ?? null,
            default => null,
        };
    }
}
