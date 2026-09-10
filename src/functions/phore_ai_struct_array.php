<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Generate a list of structured objects and hydrate each entry into the given class.
 * The provider returns an object with an "items" array; this helper returns the
 * hydrated list itself, including an empty list when the model supplies no items.
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
 * final class Topic {
 *     public function __construct(public string $name) {}
 * }
 * $topics = phore_ai_struct_array('List three PHP testing topics.', Topic::class, [
 *     'model' => 'gpt-5-mini',
 * ]);
 * foreach ($topics as $topic) {
 *     echo $topic->name, PHP_EOL;
 * }
 * </code>
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Prompt text, prompt/tool instance, or ordered collection; strings become TextPrompt instances.
 * @param class-string<T> $className Target class used to hydrate every item.
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     timeout?: positive-int,
 *     connect_timeout?: positive-int,
 *     debug_log?: bool|LoggerInterface
 * } $options Optional settings; omitted keys use the defaults described above.
 * @return list<T> Hydrated objects in provider order.
 * @throws InvalidArgumentException For invalid prompts, options or class configuration.
 * @throws \Phore\AiHarness\Client\AiRequestException If the provider request fails.
 * @throws JsonException If the structured response cannot be decoded.
 */
function phore_ai_struct_array(string|PromptType|ToolType|array $prompts, string $className, array $options = []): array
{
    /** @var list<T> $result */
    $result = Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->runCastedArray($className);

    return $result;
}
