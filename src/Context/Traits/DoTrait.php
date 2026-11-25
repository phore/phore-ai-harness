<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context\Traits;

use Phore\AiHarness\DoException;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\Result\AiInteractiveResult;
use Phore\AiHarness\ToolType\ToolType;

trait DoTrait
{
    /**
     * Execute at most one local callback round and return its continuation.
     *
     * A null response ID starts a new conversation and requires a text prompt.
     * Otherwise the stored provider response is resumed; a non-null prompt
     * is new user input (steering during an open tool round). Rebuild the same
     * prepared tools/instructions for every request. Pending tools run before
     * steering reaches the next model turn. No automatic replay protection is
     * provided; authorize IDs and serialize requests in the application.
     *
     * @param string|null $prompt Initial task, new input or null to continue.
     * @param string|null $responseId Latest trusted provider response ID.
     * @param array<string, mixed> $options Per-call context overrides.
     * @throws \InvalidArgumentException For a missing initial prompt or invalid ID.
     * @throws \RuntimeException For provider or tool failures.
     * @example $step = $context->doInteractive('Inspect the files.');
     * @example $step = $context->doInteractive(responseId: $step->responseId);
     * @see PhoreAi::runInteractive()
     * @see AiInteractiveResult
     */
    public function doInteractive(
        ?string $prompt = null,
        ?string $responseId = null,
        array $options = [],
    ): AiInteractiveResult {
        if ($prompt !== null && trim($prompt) === '') {
            $prompt = null;
        }
        if ($responseId === null && $prompt === null) {
            throw new \InvalidArgumentException('An initial interactive prompt is required.');
        }

        // Die explizite ID bestimmt den Cursor auch bei einem wiederverwendeten Context.
        $this->resumeFromResponseId($responseId);

        return $this->executeAi(
            $prompt === null ? [] : Toolkit::normalizePromptItems($prompt),
            $options,
            static fn (PhoreAi $ai): AiInteractiveResult => $ai->runInteractive(),
        );
    }

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
