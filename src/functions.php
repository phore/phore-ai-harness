<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Runs a text prompt via the high-level Phore AI facade and returns the plain output text.
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
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
 */
function phore_ai_text(string|PromptType|ToolType|array $prompts, array $options = []): string
{
    return Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->run();
}

/**
 * Runs an image generation prompt and returns generated image data.
 *
 * Strings are converted to `TextPrompt` instances. Arrays may contain strings,
 * `PromptType` instances and `ToolType` instances. If no `ImageGenerationTool`
 * is provided in the prompt list, one is created from image-related options.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 * - `size`: `auto`, `1024x1024`, `1536x1024` or `1024x1536`
 * - `output_format`: `png`, `jpeg` or `webp`
 * - `quality`: `auto`, `low`, `medium` or `high`
 * - `background`: `auto`, `transparent` or `opaque`
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     timeout?: int,
 *     connect_timeout?: int,
 *     size?: 'auto'|'1024x1024'|'1536x1024'|'1024x1536',
 *     output_format?: 'png'|'jpeg'|'webp',
 *     quality?: 'auto'|'low'|'medium'|'high',
 *     background?: 'auto'|'transparent'|'opaque'
 * } $options
 */
function phore_ai_image(string|PromptType|ToolType|array $prompts, array $options = []): ImageResultType
{
    $items = Toolkit::normalizePromptItems($prompts);
    if (!Toolkit::hasTool($items, ImageGenerationTool::class)) {
        $items[] = new ImageGenerationTool(
            size: $options['size'] ?? null,
            output_format: $options['output_format'] ?? null,
            quality: $options['quality'] ?? null,
            background: $options['background'] ?? null,
        );
    }

    return Toolkit::createAi($options)
        ->with(...$items)
        ->runImage(Toolkit::contentTypeFromImageOutputFormat($options['output_format'] ?? 'png'));
}

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


/**
 * Runs a prompt with OpenAI structured output and hydrates the response into a list of PHP objects.
 *
 * The model is instructed via structured output to return an object with an `items` array.
 * Each entry in `items` is hydrated into `$className`.
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
 * @return list<T>
 */
function phore_ai_struct_array(string|PromptType|ToolType|array $prompts, string $className, array $options = []): array
{
    /** @var list<T> $result */
    $result = Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->runCastedArray($className);

    return $result;
}



/**
 * Returns the last OpenAI Responses API request created by this process.
 */
function get_last_ai_request(): ?AiRequest
{
    return AiRequest::$last;
}

/**
 * Returns the last OpenAI Responses API response created by this process.
 */
function get_last_ai_response(): ?AiResponse
{
    return AiResponse::$last;
}
