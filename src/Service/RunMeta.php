<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

/**
 * Optional audit metadata for a ClaimIngestor::record() call.
 *
 * All fields are nullable — callers fill what they have (most commonly the
 * rendered prompt + model name + token usage returned by the LLM).
 */
final class RunMeta
{
    public function __construct(
        public readonly ?string $model = null,
        public readonly ?string $prompt = null,
        public readonly mixed $response = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $imageTokens = null,
        public readonly ?int $durationMs = null,
    ) {
    }
}
