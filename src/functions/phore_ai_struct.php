<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Runs a prompt with OpenAI structured output and hydrates the response into a PHP object.
 *
 * Strings are converted to `TextPrompt` instances. Arrays may contain strings,
 * `PromptType` instances and `ToolType` instances.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param class-string<T> $className
 * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
 * @return T
 */
function phore_ai_struct(string|PromptType|ToolType|array $prompts, string $className, array $options = []): object
{
    /** @var T $result */
    $result = Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->runCasted($className);

    return $result;
}
