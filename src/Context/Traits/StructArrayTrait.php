<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context\Traits;

use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

trait StructArrayTrait
{
    /**
     * Generate and hydrate a list of typed objects in this conversation.
     * Shared callbacks are available; an empty result is a valid empty list.
     *
     * @template T of object
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param class-string<T> $input Class used to hydrate every list element.
     * @param array<string, mixed> $options Per-call context overrides.
     * @return list<T>
     * @throws \InvalidArgumentException For an invalid class or result shape.
     * @example $items = (new AiContext())->structArray('Extract tasks.', Task::class);
     * @see \Phore\AiHarness\AiContext
     * @see PhoreAi::runCastedArray()
     */
    public function structArray(string|PromptType|ToolType|array $prompts, string $input, array $options = []): array
    {
        return $this->executeAi(
            Toolkit::normalizePromptItems($prompts),
            $options,
            static fn (PhoreAi $ai): array => $ai->runCastedArray($input),
        );
    }
}
