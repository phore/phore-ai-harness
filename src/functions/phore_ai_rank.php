<?php

declare(strict_types=1);

use Phore\AiHarness\Context\AiContextRegistry;

/**
 * Rank all supplied string/integer choices in a temporary or named AI context.
 *
 * Every supplied value appears exactly once in a determined ranking. When the
 * context is insufficient, null is returned only with allowNull=true; otherwise
 * the task error is surfaced.
 *
 * @param array<int|string, int|string|null> $choices Values or value => description mappings.
 * @param array<string, mixed> $options Common helper options plus optional ai_context.
 * @return list<string|int>|null
 * @example $ranking = phore_ai_rank(null, ['news', 'guide'], options: ['ai_context' => 'article']);
 * @see \Phore\AiHarness\AiContext::rank()
 */
function phore_ai_rank(
    ?string $prompt,
    array $choices,
    bool $allowNull = false,
    array $options = [],
): ?array {
    $context = AiContextRegistry::resolve($options);
    unset($options['ai_context']);

    return $context->rank($prompt, $choices, $allowNull, $options);
}

