<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Run prompts and optional tools and return the final plain-text response.
 * Tool callbacks may execute during the request loop.
 *
 * Options (all optional):
 * - client: OpenAiClient instance, an "openai:<apikey>" DSN, or null (default)
 *   to resolve credentials through the Keystore/default client.
 * - model: Model name; default "gpt-5-mini".
 * - timeout: Total request timeout in seconds, at least 1; default 600.
 * - connect_timeout: Connection timeout in seconds, at least 1; default 10.
 *   Both timeout options apply only when constructing a client; a supplied
 *   OpenAiClient instance keeps its own timeout settings.
 * - debug_log: false (default) disables logging; true writes ConsoleLogger output
 *   to STDERR; a LoggerInterface instance receives events and statistics.
 *   Debug mode streams text/structured responses and enables bounded retries
 *   for explicitly recoverable tool errors; image generation remains non-streaming.
 *
 * Example (after requiring vendor/autoload.php and configuring credentials):
 * <code>
 * $text = phore_ai_text('Explain dependency injection in two sentences.', [
 *     'model' => 'gpt-5-mini',
 *     'timeout' => 120,
 *     'debug_log' => true,
 * ]);
 * echo $text;
 * </code>
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Prompt text, prompt/tool instance, or ordered collection; strings become TextPrompt instances.
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     timeout?: positive-int,
 *     connect_timeout?: positive-int,
 *     debug_log?: bool|LoggerInterface
 * } $options Optional settings; omitted keys use the defaults described above.
 * @return string Final response text.
 * @throws InvalidArgumentException For invalid prompts, options or client configuration.
 * @throws \Phore\AiHarness\Client\AiRequestException If the provider request fails.
 */
function phore_ai_text(string|PromptType|ToolType|array $prompts, array $options = []): string
{
    return Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->run();
}
