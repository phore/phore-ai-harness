<?php

declare(strict_types=1);

use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\Result\AiInteractiveResult;

/**
 * Resolve a yes/no question in a temporary or named AI context.
 *
 * With allowNull=true the helper returns null when the available conversation
 * state is insufficient for a reliable answer. With the default false setting,
 * that condition is surfaced as TaskErrorException instead of being guessed.
 *
 * @param array<string, mixed> $options Common helper options plus optional ai_context.
 * @return bool|null|AiInteractiveResult
 * @example $ready = phore_ai_yes_no('Is the article ready?', options: ['ai_context' => 'article']);
 * @see \Phore\AiHarness\AiContext::yesNo()
 */
function phore_ai_yes_no(
    ?string $prompt,
    bool $allowNull = false,
    array $options = [],
): bool|AiInteractiveResult|null {
    $context = AiContextRegistry::resolve($options);
    unset($options['ai_context']);

    return $context->yesNo($prompt, $allowNull, $options);
}

