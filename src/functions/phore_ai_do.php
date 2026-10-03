<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\DoException;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Execute an AI work step without returning generated text.
 *
 * The model can reason and use supplied tools/callbacks. The completed response
 * remains in the selected AiContext for following calls. A fachlicher failure
 * returns false by default; true requests DoException, while a DoException
 * subclass requests that exact exception type. Provider/tool errors propagate.
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Instructions/sources/tools.
 * @param bool|class-string<DoException> $throw Failure policy for a completed but unsuccessful task.
 * @param array{ai_context?: AiContext|string|null, client?: OpenAiClient|string|null, model?: string, reasoning?: array|null, timeout?: int, connect_timeout?: int, debug_log?: bool|LoggerInterface} $options Common options.
 * @return bool True when the requested work completed; false for a reported fachlicher failure when throwing is disabled.
 * @throws DoException When the model reports failure and throwing is enabled.
 * @throws InvalidArgumentException For an invalid context selector, exception class or option.
 * @example $ok = phore_ai_do('Research the topic for the next step.', options: ['ai_context' => 'job']);
 * @see AiContext::do()
 * @see \Phore\AiHarness\PhoreAi::do()
 */
function phore_ai_do(
    string|PromptType|ToolType|array $prompts,
    bool|string $throw = false,
    array $options = [],
): bool {
    return AiContextRegistry::resolve($options)->do($prompts, $throw, $options);
}
