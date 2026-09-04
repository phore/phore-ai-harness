<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\ToolType;

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
