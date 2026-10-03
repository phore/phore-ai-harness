<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Generate an image using the unchanged legacy function signature.
 * Options accept ai_context (ID/object/null), common client/model/logging
 * settings and size/output_format/quality/background. Shared callbacks are
 * available before image generation; image responses stay non-streaming.
 * An explicit ImageGenerationTool retains its own generation settings.
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Instructions and source prompts/tools.
 * @param array<string, mixed> $options Common context and image-generation options.
 * @return ImageResultType Binary data and metadata; no file is written automatically.
 * @throws InvalidArgumentException For invalid settings or context selectors.
 * @throws RuntimeException For provider/tool failure or a missing image.
 * @example $image = phore_ai_image('A simple icon.', ['ai_context' => 'default', 'output_format' => 'png']);
 * @see AiContext::image()
 * @see ImageResultType::saveToFile()
 */
function phore_ai_image(string|PromptType|ToolType|array $prompts, array $options = []): ImageResultType
{
    return AiContextRegistry::resolve($options)->image($prompts, $options);
}
