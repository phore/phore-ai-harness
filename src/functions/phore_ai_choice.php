<?php

declare(strict_types=1);

use Phore\AiHarness\Context\AiContextRegistry;

/**
 * Select exactly one string/integer value in a temporary or named AI context.
 *
 * Use options['ai_context'] with the same non-empty key across helper calls to
 * continue one process-local conversation. An undetermined result returns null
 * only when allowNull is true; otherwise TaskErrorException is propagated.
 *
 * @param array<int|string, int|string|null> $choices Values or value => description mappings.
 * @param array<string, mixed> $options Common helper options plus optional selected and ai_context.
 * @return string|int|null
 * @example $tag = phore_ai_choice('Which tag fits?', ['news', 'guide'], options: ['ai_context' => 'article']);
 * @see \Phore\AiHarness\AiContext::choice()
 */
function phore_ai_choice(
    ?string $prompt,
    array $choices,
    bool $allowNull = false,
    array $options = [],
): string|int|null {
    $context = AiContextRegistry::resolve($options);
    unset($options['ai_context']);

    return $context->choice($prompt, $choices, $allowNull, $options);
}

