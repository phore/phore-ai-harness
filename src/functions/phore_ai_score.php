<?php

declare(strict_types=1);

use Phore\AiHarness\Context\AiContextRegistry;

/**
 * Score the current task/context from 0.0 to 1.0.
 *
 * A determined result is validated locally to the inclusive range 0.0..1.0.
 * An undetermined result returns null only with allowNull=true; otherwise the
 * task error is surfaced.
 *
 * @param array<string, mixed> $options Common helper options plus optional ai_context.
 * @return float|null
 * @example $score = phore_ai_score('How relevant is it?', options: ['ai_context' => 'article']);
 * @see \Phore\AiHarness\AiContext::score()
 */
function phore_ai_score(
    ?string $prompt,
    bool $allowNull = false,
    array $options = [],
): ?float {
    $context = AiContextRegistry::resolve($options);
    unset($options['ai_context']);

    return $context->score($prompt, $allowNull, $options);
}

