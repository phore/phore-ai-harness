<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use InvalidArgumentException;
use Phore\AiHarness\ToolType\CallbackTool;

/**
 * Ordered multimodal result of a single CallbackTool invocation.
 */
final readonly class MultiPrompt
{
    /**
     * Bundles existing prompts without changing their individual metadata.
     *
     * @param list<PromptType> $prompts Non-empty ordered list of prompt objects.
     * @throws InvalidArgumentException If the list is empty, not sequential or contains an invalid item.
     * @example return new MultiPrompt([$desktopImagePrompt, $mobileImagePrompt]);
     * @see CallbackTool
     */
    public function __construct(public array $prompts)
    {
        if ($prompts === [] || !array_is_list($prompts)) {
            throw new InvalidArgumentException('MultiPrompt expects a non-empty list of prompts.');
        }

        foreach ($prompts as $prompt) {
            if (!$prompt instanceof PromptType) {
                throw new InvalidArgumentException('MultiPrompt expects only PromptType instances.');
            }
        }
    }
}
