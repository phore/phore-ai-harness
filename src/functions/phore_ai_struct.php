<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Generate a structured response and hydrate it into an instance of the given class.
 * The class schema defines the provider output format; prompts can also supply tools.
 *
 * Options (all optional):
 * - client: OpenAiClient instance, an "openai:<apikey>" DSN, or null (default)
 *   to resolve credentials through the Keystore/default client.
 * - model: Model name; default "gpt-5-mini".
 * - reasoning: Responses API settings; default ['effort' => 'low']; null omits it.
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
 * final class Answer {
 *     public function __construct(public string $summary) {}
 * }
 * $answer = phore_ai_struct('Summarize what JSON Patch does.', Answer::class, [
 *     'model' => 'gpt-5-mini',
 *     'debug_log' => true,
 * ]);
 * echo $answer->summary;
 * </code>
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Prompt text, prompt/tool instance, or ordered collection; strings become TextPrompt instances.
 * @param class-string<T> $className Target class whose schema defines the structured output.
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     reasoning?: array<string, mixed>|null,
 *     timeout?: positive-int,
 *     connect_timeout?: positive-int,
 *     debug_log?: bool|LoggerInterface
 * } $options Optional settings; omitted keys use the defaults described above.
 * @return T Hydrated instance of $className.
 * @throws InvalidArgumentException For invalid prompts, options or class configuration.
 * @throws \Phore\AiHarness\Client\AiRequestException If the provider request fails.
 * @throws JsonException If the structured response cannot be decoded.
 */
function phore_ai_struct(string|PromptType|ToolType|array $prompts, string $className, array $options = []): object
{
    /** @var T $result */
    $result = Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->runCasted($className);

    return $result;
}
