<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Generate text, or edit options['input'], through a temporary or shared context.
 * The legacy ($prompts, $options) signature and string return are unchanged.
 * Input null/absent generates; a supplied string, including '', is edited via
 * exact replacement batches and returned as the complete locally assembled text.
 * Shared context callbacks and per-call tools may run during either mode.
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Instructions/sources; strings are instruction-enabled.
 * @param array{ai_context?: AiContext|string|null, input?: string|null, client?: OpenAiClient|string|null, model?: string, reasoning?: array|null, timeout?: int, connect_timeout?: int, debug_log?: bool|LoggerInterface} $options Common options; see docs/ai-context.md. Without ai_context each call is isolated.
 * @return string Generated or edited text, never the edit callback's commentary.
 * @throws InvalidArgumentException For invalid context selectors or options.
 * @throws RuntimeException For provider/tool failure or an unfinished edit.
 * @example $text = phore_ai_text('Correct spelling.', ['input' => $draft, 'ai_context' => 'default']);
 * @see AiContext::text()
 */
function phore_ai_text(string|PromptType|ToolType|array $prompts, array $options = []): string
{
    return AiContextRegistry::resolve($options)->text($prompts, $options['input'] ?? null, $options);
}
