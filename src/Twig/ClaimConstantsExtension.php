<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Twig;

use Survos\ClaimsBundle\Entity\Claim;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes every public constant on Claim as a Twig global prefixed with CLAIM_.
 *
 * e.g. CLAIM_PRED_HTR_TEXT, CLAIM_PRED_OCR_TEXT, CLAIM_SUBJECT_IMAGE
 *
 * Lets templates compare claim predicates without using the brittle
 * constant('Survos\\ClaimsBundle\\Entity\\Claim::PRED_HTR_TEXT') syntax.
 */
final class ClaimConstantsExtension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        $globals = [];
        foreach ((new \ReflectionClass(Claim::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC) as $name => $value) {
            $globals['CLAIM_'.$name] = $value;
        }

        return $globals;
    }
}
