<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use InvalidArgumentException;
use LogicException;
use Phore\AiHarness\Context\Traits\FileTrait;
use Phore\AiHarness\Context\Traits\ImageTrait;
use Phore\AiHarness\Context\Traits\StructArrayTrait;
use Phore\AiHarness\Context\Traits\StructTrait;
use Phore\AiHarness\Context\Traits\TextTrait;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\OutputFormat\OutputFormat;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Explicit, process-local conversation cursor shared by the typed operations.
 *
 * Cloning copies the cursor and checkpoint/registration arrays, not external
 * services, callback closures, files or provider caches. A context is not a
 * filesystem transaction and must not be used concurrently or reentrantly.
 *
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

    private const OPTION_KEYS = ['client', 'model', 'reasoning', 'timeout', 'connect_timeout', 'debug_log'];

    private array $defaults;
    private ?OpenAiClient $client = null;
    private mixed $clientSelection = null;
    private ?string $responseId = null;
    /** @var array<string, CallbackTool> */
    private array $callbacks = [];
    /** @var list<array{name: ?string, responseId: ?string}> */
    private array $checkpoints = [];
    private bool $running = false;

    /**
     * Configure defaults and callbacks without making a provider request.
     * Credentials use the existing default client/Keystore on the first call.
     * Method options override these defaults for that call only.
     *
     * @param array<string, mixed> $options Existing common helper options.
     * @param list<CallbackTool> $callbacks Tools available in every operation.
     * @throws InvalidArgumentException For invalid callback registrations.
     * @example $context = new AiContext(['model' => 'gpt-5-mini'], callbacks: [$askUser]);
     * @see addCallback()
     */
    public function __construct(array $options = [], array $callbacks = [])
    {
        $this->defaults = array_intersect_key($options, array_flip(self::OPTION_KEYS));
        foreach ($callbacks as $callback) {
            if (!$callback instanceof CallbackTool) {
                throw new InvalidArgumentException('Context callbacks must be CallbackTool instances.');
            }
            $this->addCallback($callback);
        }
    }

    /**
     * Register a shared callback; never silently replace another tool.
     * The callback runs only when requested by the model. Its own side effects
     * are not undone by rollback. Register before starting an operation.
     *
     * @return $this
     * @throws InvalidArgumentException For duplicate or reserved names.
     * @throws LogicException While this context is running.
     * @example $context->addCallback(new CallbackTool($ask, 'ask_user_question'));
     * @see ToolType\CallbackTool
     */
    public function addCallback(CallbackTool $callback): self
    {
        $this->assertIdle();
        $name = $callback->name();
        if (in_array($name, ['write_text', 'write_files'], true) || isset($this->callbacks[$name])) {
            throw new InvalidArgumentException('Duplicate or reserved context callback: ' . $name);
        }
        $this->callbacks[$name] = $callback;
        return $this;
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
     * This restores only the response cursor, never files, callbacks, options,
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
     * Callback closures and the configured client remain shared dependencies.
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
            $effective = array_replace($this->defaults, $options);
            unset($effective['ai_context']);
            $selection = $effective['client'] ?? null;
            if ($this->client !== null) {
                if ($selection !== null && $selection !== $this->client && $selection !== $this->clientSelection) {
                    throw new InvalidArgumentException('Cannot change the client of an initialized AI context; create a new context.');
                }
                $effective['client'] = $this->client;
            }
            $ai = Toolkit::createAi($effective);
            if ($this->client === null) {
                $this->client = $ai->getOpenAiClient();
                $this->clientSelection = $selection;
            }

            // Gemeinsame Callbacks werden pro Request mit den lokalen Tools kombiniert.
            $items = $this->withContextCallbacks($items);
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

    private function withContextCallbacks(array $items): array
    {
        $names = $this->callbacks;
        $merged = array_values($this->callbacks);
        foreach ($items as $item) {
            if ($item instanceof CallbackTool) {
                $name = $item->name();
                if (isset($names[$name])) {
                    if ($names[$name] === $item) {
                        continue;
                    }
                    throw new InvalidArgumentException('Duplicate callback tool name: ' . $name);
                }
                $names[$name] = $item;
            }
            $merged[] = $item;
        }
        return $merged;
    }

    private function assertIdle(): void
    {
        if ($this->running) {
            throw new LogicException('AI context is already running; use an independent context for another operation.');
        }
    }

    private function validateCheckpointName(?string $name): void
    {
        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('Checkpoint name must not be empty.');
        }
    }
}
