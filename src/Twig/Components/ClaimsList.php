<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Twig\Components;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\DataContracts\Dto\Claim\TermClaim;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Generic claims list for any subject (image, item, …).
 *
 * Usage:
 *   <twig:ClaimsList subjectType="image" :subjectId="image.id"/>
 *   <twig:ClaimsList :subject="image"/>       {# type derived from class short-name #}
 *   <twig:ClaimsList :subject="image" source="enrich_from_thumbnail@1.0"/>
 */
#[AsTwigComponent('ClaimsList', template: '@SurvosClaims/components/ClaimsList.html.twig')]
final class ClaimsList
{
    public ?object $subject = null;

    public ?string $subjectType = null;

    public ?string $subjectId = null;

    public ?string $scope = null;

    /** Optional single-source filter, e.g. 'enrich_from_thumbnail@1.0'. */
    public ?string $source = null;

    /**
     * Optional comma-separated list of sources to include, e.g.
     * 'ocr_mistral@1.0,transcribe_handwriting@1.0,extract_metadata@1.0'.
     * Takes precedence over $source when both are set.
     */
    public ?string $sources = null;

    /**
     * Optional comma-separated list of predicates to include, e.g.
     * 'dcterms:subject,foaf:Person,dcterms:spatial'. Applied after the
     * source filter; empty = all predicates.
     */
    public ?string $predicates = null;

    /**
     * Optional comma-separated list of predicates to exclude. Useful for keeping
     * audit views compact when bulky text claims have a dedicated panel.
     */
    public ?string $excludePredicates = null;

    /**
     * Only include claims whose source ends with this suffix, e.g. "@import"
     * to select all import/source claims regardless of aggregator name.
     * Empty = no suffix filter.
     */
    public ?string $sourceSuffix = null;

    /**
     * Render style: 'table' (default — grouped by source, every field visible)
     * or 'chips' (value + basis + confidence marker; good for tag-like predicates).
     */
    public string $layout = 'table';

    public function __construct(private readonly ClaimRepository $claims)
    {
    }

    /** @return list<Claim> */
    public function getClaims(): array
    {
        [$type, $id] = $this->resolve();
        if (null === $type || null === $id) {
            return [];
        }

        if (null !== $this->sources && '' !== trim($this->sources)) {
            $wanted = array_map('trim', explode(',', $this->sources));
            $wanted = array_values(array_filter($wanted, static fn ($s) => '' !== $s));
            if ([] === $wanted) {
                $claims = $this->claims->findForSubject($type, $id, $this->scope);
            } else {
                $claims = [];
                foreach ($wanted as $src) {
                    foreach ($this->claims->findForSubjectAndSource($type, $id, $src, $this->scope) as $c) {
                        $claims[] = $c;
                    }
                }
            }
        } elseif (null !== $this->source) {
            $claims = $this->claims->findForSubjectAndSource($type, $id, $this->source, $this->scope);
        } else {
            $claims = $this->claims->findForSubject($type, $id, $this->scope);
        }

        if (null !== $this->predicates && '' !== trim($this->predicates)) {
            $wantedPred = array_flip(array_values(array_filter(
                array_map('trim', explode(',', $this->predicates)),
                static fn ($s) => '' !== $s,
            )));
            if ([] !== $wantedPred) {
                $claims = array_values(array_filter(
                    $claims,
                    static fn (Claim $c) => isset($wantedPred[$c->predicate]),
                ));
            }
        }

        if (null !== $this->sourceSuffix && '' !== trim($this->sourceSuffix)) {
            $suffix = trim($this->sourceSuffix);
            $claims = array_values(array_filter(
                $claims,
                static fn (Claim $c) => str_ends_with($c->source, $suffix),
            ));
        }

        if (null !== $this->excludePredicates && '' !== trim($this->excludePredicates)) {
            $excludedPred = array_flip(array_values(array_filter(
                array_map('trim', explode(',', $this->excludePredicates)),
                static fn ($s) => '' !== $s,
            )));
            if ([] !== $excludedPred) {
                $claims = array_values(array_filter(
                    $claims,
                    static fn (Claim $c) => !isset($excludedPred[$c->predicate]),
                ));
            }
        }

        return $claims;
    }

    public function getResolvedSubjectType(): ?string
    {
        return $this->resolve()[0];
    }

    public function getResolvedSubjectId(): ?string
    {
        return $this->resolve()[1];
    }

    /**
     * @return list<array{
     *     label: string,
     *     confidence: float,
     *     basis: ?string,
     *     predicate: string,
     *     source: string
     * }>
     */
    public function getTagClaimViews(): array
    {
        $claims = $this->getClaims();
        $rows = [];

        for ($i = 0, $count = count($claims); $i < $count; ++$i) {
            $claim = $claims[$i];
            $label = $this->claimLabel($claim->value);

            if ($legacy = $this->legacyTripletView($claims, $i, $label)) {
                $rows[] = $legacy;
                $i += 2;
                continue;
            }

            if (null !== $this->confidenceWord($claim->value)) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'confidence' => $claim->confidence,
                'basis' => $claim->basis,
                'predicate' => $claim->predicate,
                'source' => $claim->source,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $rows;
    }

    /**
     * Older imports sometimes stored keyword triplets as three adjacent claims:
     * term, confidence word, basis. Render those as one tag claim without
     * treating "medium" or "high" as tags.
     *
     * @param list<Claim> $claims
     *
     * @return array{label: string, confidence: float, basis: ?string, predicate: string, source: string}|null
     */
    private function legacyTripletView(array $claims, int $offset, string $label): ?array
    {
        if ($offset + 2 >= count($claims)) {
            return null;
        }

        $claim = $claims[$offset];
        $confidence = $this->confidenceWord($claims[$offset + 1]->value);
        if (
            null !== $confidence
            && $this->isSameClaimSeries($claim, $claims[$offset + 1])
            && $this->isSameClaimSeries($claim, $claims[$offset + 2])
        ) {
            $rows[] = [
                'label' => $label,
                'confidence' => $confidence,
                'basis' => $this->claimLabel($claims[$offset + 2]->value),
                'predicate' => $claim->predicate,
                'source' => $claim->source,
            ];
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function resolve(): array
    {
        $type = $this->subjectType;
        $id = $this->subjectId;

        if ((null === $type || null === $id) && null !== $this->subject) {
            $type ??= strtolower((new \ReflectionClass($this->subject))->getShortName());
            if (null === $id && method_exists($this->subject, 'getId')) {
                $id = (string) $this->subject->getId();
            } elseif (null === $id && property_exists($this->subject, 'id')) {
                $id = (string) $this->subject->id;
            }
        }

        return [$type, $id];
    }

    private function isSameClaimSeries(Claim $a, Claim $b): bool
    {
        return $a->predicate === $b->predicate && $a->source === $b->source;
    }

    private function confidenceWord(mixed $value): ?float
    {
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'high' => 0.9,
            'medium' => 0.6,
            'low' => 0.3,
            default => null,
        };
    }

    private function claimLabel(mixed $value): string
    {
        if (is_array($value) || $value instanceof \stdClass) {
            $term = TermClaim::from($value);

            return '' !== $term->term ? $term->term : '—';
        }

        if (null === $value || '' === $value) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return trim((string) $value);
    }
}
