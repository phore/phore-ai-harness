<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context\Traits;

use Phore\AiHarness\DoException;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

trait DoTrait
{
    /**
     * Execute a task in this conversation without returning generated text.
     *
     * The operation advances the same response cursor as text(), file() and the
     * structured methods. A normal task failure returns false. With throw=true
     * it raises DoException; a supplied subclass must inherit that constructor.
     * Provider, tool and task-contract exceptions are never converted to false.
     *
     * @param string|PromptType|ToolType|array $prompts Instructions and sources/tools.
     * @param bool|class-string<DoException> $throw Failure handling for an otherwise valid task.
     * @param array<string, mixed> $options Per-call overrides of context defaults.
     * @return bool True only when the requested work was completed successfully.
     * @throws DoException For a reported task failure when throwing is enabled.
     * @example $context->do('Research the source.'); $text = $context->text('Summarize it.');
     * @see \Phore\AiHarness\AiContext
     * @see PhoreAi::do()
     */
    public function do(
        string|PromptType|ToolType|array $prompts,
        bool|string $throw = false,
        array $options = [],
    ): bool {
        $items = Toolkit::normalizePromptItems($prompts);

        return $this->executeAi(
            $items,
            $options,
            static fn (PhoreAi $ai): bool => $ai->do($throw),
        );
    }
}
