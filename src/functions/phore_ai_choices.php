<?php

declare(strict_types=1);

use Phore\AiHarness\Context\AiContextRegistry;

/**
 * Select multiple string/integer values in a temporary or named AI context.
 *
 * The result respects min/max and preserves model preference order. A null
 * result means the task was explicitly undetermined and is only possible when
 * allowNull is true; an empty array remains a determined selection of none.
 *
 * @param array<int|string, int|string|null> $choices Values or value => description mappings.
 * @param array<string, mixed> $options Common helper options plus optional selected and ai_context.
 * @return list<string|int>|null
 * @example $tags = phore_ai_choices(null, ['news', 'guide'], min: 0, max: 2, options: ['ai_context' => 'article']);
 * @see \Phore\AiHarness\AiContext::choices()
 */
function phore_ai_choices(
    ?string $prompt,
    array $choices,
    int $min = 0,
    ?int $max = null,
    bool $allowNull = false,
    array $options = [],
): ?array {
    $context = AiContextRegistry::resolve($options);
    unset($options['ai_context']);

    return $context->choices($prompt, $choices, $min, $max, $allowNull, $options);
}

