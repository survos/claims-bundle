<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Twig\Components;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('OcrClaimsPanel', template: '@SurvosClaims/components/OcrClaimsPanel.html.twig')]
final class OcrClaimsPanel
{
    public ?object $subject = null;

    public ?string $subjectType = null;

    public ?string $subjectId = null;

    public ?string $scope = null;

    public function __construct(private readonly ClaimRepository $claims)
    {
    }

    /** @return list<Claim> */
    public function ocrClaims(): array
    {
        [$type, $id] = $this->resolve();
        if (null === $type || null === $id) {
            return [];
        }

        $wanted = [
            'ai:observationProse' => true,
            Claim::PRED_HTR_TEXT => true,
            Claim::PRED_PRINTED_TEXT => true,
            Claim::PRED_OCR_TEXT => true,
        ];

        $claims = array_values(array_filter(
            $this->claims->findForSubject($type, $id, $this->scope),
            static fn (Claim $claim): bool => isset($wanted[$claim->predicate]) && is_string($claim->value) && '' !== trim($claim->value),
        ));

        $specificBySource = [];
        foreach ($claims as $claim) {
            if (Claim::PRED_OCR_TEXT !== $claim->predicate) {
                $specificBySource[$claim->source] = true;
            }
        }

        $claims = array_values(array_filter(
            $claims,
            static fn (Claim $claim): bool => Claim::PRED_OCR_TEXT !== $claim->predicate || !isset($specificBySource[$claim->source]),
        ));

        usort($claims, static function (Claim $a, Claim $b): int {
            $rank = [
                'ai:observationProse' => 4,
                Claim::PRED_HTR_TEXT => 3,
                Claim::PRED_PRINTED_TEXT => 2,
                Claim::PRED_OCR_TEXT => 1,
            ];
            $rankCmp = ($rank[$b->predicate] ?? 0) <=> ($rank[$a->predicate] ?? 0);
            if (0 !== $rankCmp) {
                return $rankCmp;
            }
            if ($a->confidence !== $b->confidence) {
                return $b->confidence <=> $a->confidence;
            }

            return $b->createdAt <=> $a->createdAt;
        });

        return $claims;
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
}
