<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context\Traits;

use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\ToolType;

trait ImageTrait
{
    /**
     * Generate an image in the current conversation, including shared callbacks.
     * The returned image is not persisted; call saveToFile() explicitly.
     * An explicitly supplied ImageGenerationTool keeps its generation settings.
     *
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param array<string, mixed> $options Common and image-generation options.
     * @throws \RuntimeException For a provider/tool failure or missing image.
     * @example $image = (new AiContext())->image('Create a simple icon.');
     * @see \Phore\AiHarness\AiContext
     * @see ImageResultType::saveToFile()
     */
    public function image(string|PromptType|ToolType|array $prompts, array $options = []): ImageResultType
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
        $contentType = Toolkit::contentTypeFromImageOutputFormat($options['output_format'] ?? 'png');
        return $this->executeAi($items, $options, static fn (PhoreAi $ai): ImageResultType => $ai->runImage($contentType));
    }
}
