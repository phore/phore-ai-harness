<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Generate an image and return its binary data, content type and file extension.
 * If prompts contain no ImageGenerationTool, one is created from the image options.
 * When an explicit ImageGenerationTool is supplied, its generation settings win;
 * output_format still selects the fallback content type used to decode the result.
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
 * - size: "auto", "1024x1024", "1536x1024" or "1024x1536"; omitted by default
 *   so the provider chooses the size.
 * - output_format: "png", "jpeg" or "webp"; omitted from the generated tool by
 *   default, with "png" used as the local result content-type fallback.
 * - quality: "auto", "low", "medium" or "high"; omitted by default (provider choice).
 * - background: "auto", "transparent" or "opaque"; omitted by default (provider choice).
 *
 * Example (after requiring vendor/autoload.php and configuring credentials):
 * <code>
 * $image = phore_ai_image('A simple blue sailboat icon on white.', [
 *     'size' => '1024x1024',
 *     'output_format' => 'png',
 *     'quality' => 'high',
 *     'background' => 'opaque',
 * ]);
 * $image->saveToFile(__DIR__ . '/sailboat.png');
 * </code>
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Prompt text, prompt/tool instance, or ordered collection; strings become TextPrompt instances.
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     reasoning?: array<string, mixed>|null,
 *     timeout?: positive-int,
 *     connect_timeout?: positive-int,
 *     debug_log?: bool|LoggerInterface,
 *     size?: 'auto'|'1024x1024'|'1536x1024'|'1024x1536',
 *     output_format?: 'png'|'jpeg'|'webp',
 *     quality?: 'auto'|'low'|'medium'|'high',
 *     background?: 'auto'|'transparent'|'opaque'
 * } $options Optional settings; omitted keys use the defaults described above.
 * @return ImageResultType Generated image; call saveToFile() to persist it.
 * @throws InvalidArgumentException For invalid prompts, options or image settings.
 * @throws \Phore\AiHarness\Client\AiRequestException If the provider request fails.
 * @throws RuntimeException If the response contains no usable image.
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
