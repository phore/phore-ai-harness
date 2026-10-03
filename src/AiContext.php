<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use InvalidArgumentException;
use LogicException;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Context\Traits\FileTrait;
use Phore\AiHarness\Context\Traits\ImageTrait;
use Phore\AiHarness\Context\Traits\StructArrayTrait;
use Phore\AiHarness\Context\Traits\StructTrait;
use Phore\AiHarness\Context\Traits\TextTrait;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\OutputFormat\OutputFormat;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Explicit, process-local conversation cursor shared by the typed operations.
 *
 * Constructor prompts form the prepared context. Content prompts are sent when
 * a root conversation starts; tools and system prompts are attached to every
 * request so they remain available while following previous_response_id.
 *
 * Cloning copies the cursor, prompt stack and checkpoints, not external service
 * state, callback closure state, files or provider caches. A context is not a
 * filesystem transaction and must not be used concurrently or reentrantly.
 *
 * @see AiOptions
 * @see Context\AiContextRegistry
 * @see https://developers.openai.com/api/docs/guides/conversation-state
 */
final class AiContext
{
    use TextTrait;
    use FileTrait;
    use StructTrait;
    use StructArrayTrait;
    use ImageTrait;

    /** @var list<PromptType|ToolType> */
    private array $prompts;
    private AiOptions $defaults;
    private ?OpenAiClient $client = null;
    private mixed $clientSelection = null;
    private ?string $responseId = null;
    /** @var list<array{name: ?string, responseId: ?string}> */
    private array $checkpoints = [];
    private bool $running = false;

    /**
     * Prepare reusable prompt/tool context and common defaults without a request.
     *
     * Strings, PromptType and ToolType values use the same normalization as the
     * global helpers. AiOptions::fromArray() normalizes arrays and passes an
     * existing options object through unchanged. Method options override these
     * defaults for one call; the first resolved client stays bound afterwards.
     *
     * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Reusable context items.
     * @param AiOptions|array<string, mixed> $options Common context defaults.
     * @throws InvalidArgumentException For invalid prompts, options or duplicate/reserved callback tools.
     * @example $context = new AiContext(prompts: [$skill, $tool], options: ['model' => 'gpt-5-mini']);
     * @see AiOptions::fromArray()
     * @see Toolkit::normalizePromptItems()
     */
    public function __construct(
        string|PromptType|ToolType|array $prompts = [],
        AiOptions|array $options = [],
    ) {
        $this->prompts = $this->normalizeContextItems(Toolkit::normalizePromptItems($prompts));
        $this->defaults = AiOptions::fromArray($options);
    }

    /**
     * Save the current conversation position without making an API request.
     * A repeated name replaces that marker and makes it the latest checkpoint.
     * Anonymous checkpoints are independent markers. Empty names are invalid.
     *
     * @return $this
     * @throws InvalidArgumentException For an empty name.
     * @throws LogicException While an operation is running.
     * @example $context->setCheckpoint('source-loaded');
     * @example $context->setCheckpoint();
     * @see rollback()
     */
    public function setCheckpoint(?string $name = null): self
    {
        $this->assertIdle();
        $this->validateCheckpointName($name);
        if ($name !== null) {
            $this->checkpoints = array_values(array_filter(
                $this->checkpoints,
                static fn (array $checkpoint): bool => $checkpoint['name'] !== $name,
            ));
        }
        $this->checkpoints[] = ['name' => $name, 'responseId' => $this->responseId];

        return $this;
    }

    /**
     * Restore a named checkpoint, or the most recently set marker when omitted.
     * Markers are not consumed; repeated rollback restores the same position.
     * This restores only the response cursor, never files, prompt/tool defaults,
     * accrued usage/costs or external effects. The next request forms a branch.
     *
     * @return $this
     * @throws LogicException If no matching checkpoint exists or a call is running.
     * @throws InvalidArgumentException For an empty name.
     * @example $context->rollback('source-loaded');
     * @example $context->rollback();
     * @see setCheckpoint()
     */
    public function rollback(?string $name = null): self
    {
        $this->assertIdle();
        $this->validateCheckpointName($name);
        for ($index = count($this->checkpoints) - 1; $index >= 0; $index--) {
            $checkpoint = $this->checkpoints[$index];
            if ($name === null || $checkpoint['name'] === $name) {
                $this->responseId = $checkpoint['responseId'];

                return $this;
            }
        }

        throw new LogicException('AI context checkpoint does not exist: ' . ($name ?? '(latest)'));
    }

    /**
     * Return the provider response cursor, or null before the first completion.
     * This is a provider identifier, not a registry ID or a guaranteed cache hit.
     *
     * @example $responseId = $context->getResponseId();
     * @see Context\AiContextRegistry
     */
    public function getResponseId(): ?string
    {
        return $this->responseId;
    }

    /**
     * Fork an idle context at its current position; the original stays unchanged.
     * Prompt/tool objects and the configured client remain shared dependencies.
     *
     * @throws LogicException If cloning is attempted during an operation.
     * @example $alternative = clone $context;
     * @see setCheckpoint()
     */
    public function __clone()
    {
        $this->assertIdle();
    }

    /** @internal Execute one operation through the existing provider facade. */
    private function executeAi(array $items, array $options, callable $operation, ?OutputFormat $format = null): mixed
    {
        $this->assertIdle();
        $this->running = true;
        $ai = null;

        try {
            // Aufrufoptionen bleiben lokal; ein laufender Kontext behaelt seinen Client.
            $effective = array_replace($this->defaults->toArray(), $options);
            unset($effective['ai_context']);
            $selection = $effective['client'] ?? null;
            if ($this->client !== null) {
                $explicitSelection = $options['client'] ?? null;
                if (
                    $explicitSelection !== null
                    && $explicitSelection !== $this->client
                    && $explicitSelection !== $this->clientSelection
                ) {
                    throw new InvalidArgumentException(
                        'Cannot change the client of an initialized AI context; create a new context.',
                    );
                }
                $effective['client'] = $this->client;
            }

            $ai = Toolkit::createAi($effective);
            if ($this->client === null) {
                $this->client = $ai->getOpenAiClient();
                $this->clientSelection = $selection;
            }

            // Daten-Prompts werden beim Root geladen; Tools/Systemprompts bleiben request-lokal aktiv.
            $items = $this->withContextItems($items);
            $ai = $ai->with(...$items)->withPreviousResponseId($this->responseId);
            if ($format !== null) {
                $ai = $ai->withOutput($format);
            }

            return $operation($ai);
        } finally {
            // Nur abgeschlossene Tool-Schleifen liefern einen fortsetzbaren Cursor.
            // Auch bei anschliessenden lokalen Decode-Fehlern bleibt dieser erhalten.
            if ($ai !== null && $ai->getLastResponseId() !== null) {
                $this->responseId = $ai->getLastResponseId();
            }
            $this->running = false;
        }
    }

    /**
     * @param list<PromptType|ToolType> $items
     * @return list<PromptType|ToolType>
     */
    private function normalizeContextItems(array $items): array
    {
        $callbacks = [];
        $normalized = [];

        foreach ($items as $item) {
            if ($item instanceof CallbackTool) {
                $name = $item->name();
                if (in_array($name, ['write_text', 'write_files'], true)) {
                    throw new InvalidArgumentException('Reserved context callback tool name: ' . $name);
                }
                if (isset($callbacks[$name])) {
                    if ($callbacks[$name] === $item) {
                        continue;
                    }
                    throw new InvalidArgumentException('Duplicate context callback tool name: ' . $name);
                }
                $callbacks[$name] = $item;
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param list<PromptType|ToolType> $items
     * @return list<PromptType|ToolType>
     */
    private function withContextItems(array $items): array
    {
        $contextItems = $this->responseId === null
            ? $this->prompts
            : array_values(array_filter(
                $this->prompts,
                static fn (PromptType|ToolType $item): bool => $item instanceof ToolType || $item instanceof SystemPrompt,
            ));

        $callbacks = [];
        $merged = [];
        foreach ([...$contextItems, ...$items] as $item) {
            if ($item instanceof CallbackTool) {
                $name = $item->name();
                if (isset($callbacks[$name])) {
                    if ($callbacks[$name] === $item) {
                        continue;
                    }
                    throw new InvalidArgumentException('Duplicate callback tool name: ' . $name);
                }
                $callbacks[$name] = $item;
            }
            $merged[] = $item;
        }

        return $merged;
    }

    /** @param class-string<ToolType> $className */
    private function hasContextTool(string $className): bool
    {
        return Toolkit::hasTool($this->prompts, $className);
    }

    private function assertIdle(): void
    {
        if ($this->running) {
            throw new LogicException(
                'AI context is already running; use an independent context for another operation.',
            );
        }
    }

    private function validateCheckpointName(?string $name): void
    {
        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('Checkpoint name must not be empty.');
        }
    }
}
