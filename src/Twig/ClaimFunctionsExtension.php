<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions for displaying claim metadata consistently.
 */
final class ClaimFunctionsExtension
{
    /**
     * Returns the Bootstrap/Tabler color name for a confidence score.
     *
     * Input: integer 0–100 (the canonical confidence scale).
     * Output: 'success' | 'warning' | 'secondary'
     *
     * Usage:
     *   class="badge text-bg-{{ confidence_class(claim.confidence) }}"
     *   class="badge bg-{{ confidence_class(claim.confidence) }}-lt"
     */
    #[AsTwigFunction('confidence_class')]
    public function confidenceClass(int $score): string
    {
        return match (true) {
            $score >= 80 => 'success',
            $score >= 50 => 'warning',
            default => 'secondary',
        };
    }
}
