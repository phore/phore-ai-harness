<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Content\AiContent;
use Phore\AiHarness\Content\AiContentResultSet;
use Phore\AiHarness\Context\Traits\DoTrait;
use Phore\AiHarness\Context\Traits\FileTrait;
use Phore\AiHarness\Context\Traits\ImageTrait;
use Phore\AiHarness\Context\Traits\SimpleTypesTrait;
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
    private const STATE_VERSION = 1;
    private const STATE_PROVIDER = 'open_ai';

    use DoTrait;
    use TextTrait;
    use FileTrait;
    use StructTrait;
    use StructArrayTrait;
    use ImageTrait;
    use SimpleTypesTrait;

    /** @var list<PromptType|ToolType> */
    private array $prompts;
    /** @var list<PromptType|ToolType> */
    private array $pendingPrompts = [];
    /** @var array<string, AiContent> */
    private array $contentById = [];
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
        $this->registerContentItems($this->prompts);
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
     * Return content registered under an exact unique ID.
     *
     * @return AiContent|null Null when the ID is not part of this context.
     * @example $invoice = $context->getContentById('invoice');
     * @see queryContent()
     */
    public function getContentById(string $id): ?AiContent
    {
        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('AI content ID must not be empty.');
        }

        return $this->contentById[$id] ?? null;
    }

    /**
     * Select content matching a natural-language query.
     *
     * The model can inspect all content already attached to this conversation.
     * It chooses only from server-provided unique IDs; returned IDs are resolved
     * back to the original AiContent instances before leaving this method.
     *
     * @param AiOptions|array<string, mixed>|string|null $options Per-call AI options.
     * @return AiContentResultSet Matching content; an empty set is valid.
     * @example $images = $context->queryContent('Which images show the damaged package?');
     * @see getContentById()
     */
    public function queryContent(
        string $prompt,
        AiOptions|array|string|null $options = null,
    ): AiContentResultSet {
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('AI content query must not be empty.');
        }

        $contents = array_values($this->contentById);
        if ($contents === []) {
            return new AiContentResultSet([], new self());
        }

        $choices = [];
        foreach ($contents as $content) {
            $parts = ['class=' . $content::class];

            if ($content->fileName !== null) {
                $parts[] = 'file=' . $content->fileName;
            }
            if ($content->aliases !== []) {
                $parts[] = 'aliases=' . implode(', ', $content->aliases);
            }
            if ($content->description !== null) {
                $parts[] = 'description=' . $content->description;
            }
            if ($content->instructions !== '') {
                $parts[] = 'instructions=' . $content->instructions;
            }

            $choices[$content->id] = implode('; ', $parts);
        }

        $ids = $this->choices(
            $prompt,
            $choices,
            min: 0,
            max: count($choices),
            options: $options,
        );

        $matched = [];
        foreach ($ids ?? [] as $id) {
            if (!is_string($id) || !isset($this->contentById[$id])) {
                throw new \RuntimeException('AI content query returned an unknown content ID.');
            }

            $matched[] = $this->contentById[$id];
        }

        return new AiContentResultSet($matched, new self(prompts: $matched));
    }

    /**
     * Clone a fresh context and append prepared prompts/tools.
     *
     * Prepared source material cannot be added after the provider conversation
     * started, because root content is not resent with previous_response_id.
     */
    public function withPrepared(string|PromptType|ToolType|array $prompts): self
    {
        $this->assertIdle();
        if ($this->responseId !== null) {
            throw new LogicException('Cannot add prepared content after the AI context has started.');
        }

        $clone = clone $this;
        $items = $clone->normalizeContextItems(Toolkit::normalizePromptItems($prompts));
        $clone->registerContentItems($items);
        $clone->prompts = $clone->normalizeContextItems([
            ...$clone->prompts,
            ...$items,
        ]);

        return $clone;
    }

    /**
     * Clone this context and attach source material without mutating the original.
     *
     * Fresh contexts receive the source as prepared root content. Started
     * contexts queue it for the next request exactly once, so immutable content
     * can be rebound to an existing conversation branch.
     *
     * @param string|PromptType|ToolType|array $prompts Source items to attach.
     * @return self Cloned context branch.
     * @throws LogicException While an operation is running.
     * @example $branch = $context->withSource($document);
     * @see withPrepared()
     */
    public function withSource(string|PromptType|ToolType|array $prompts): self
    {
        $this->assertIdle();
        $items = $this->normalizeContextItems(Toolkit::normalizePromptItems($prompts));
        $clone = clone $this;
        $clone->registerContentItems($items);

        if ($clone->responseId === null) {
            $clone->prompts = $clone->normalizeContextItems([
                ...$clone->prompts,
                ...$items,
            ]);
        } else {
            $clone->pendingPrompts = $clone->normalizeContextItems([
                ...$clone->pendingPrompts,
                ...$items,
            ]);
        }

        return $clone;
    }

    /**
     * Export the resumable conversation cursor as a compact JSON string.
     *
     * The export intentionally contains no prompt, tool, client or model
     * configuration. The application rebuilds those from code before import.
     * A setup hash protects against resuming with changed prepared prompts/tools.
     * Checkpoints are included because they are provider response cursors too.
     *
     * @return string Versioned JSON containing export time, provider, setup hash and cursor state.
     * @throws LogicException While an operation is running.
     * @throws JsonException If the state cannot be encoded.
     * @example $_SESSION['ai_state'] = $context->exportState();
     * @see importState()
     * @see ResumeOptions
     */
    public function exportState(): string
    {
        $this->assertIdle();

        return Toolkit::jsonEncode([
            'version' => self::STATE_VERSION,
            'exportedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'provider' => self::STATE_PROVIDER,
            'setupHash' => $this->setupHash(),
            'state' => [
                'responseId' => $this->responseId,
                'checkpoints' => $this->checkpoints,
            ],
        ]);
    }

    /**
     * Restore a previously exported conversation cursor into this context.
     *
     * The constructor prompt/tool setup must already be rebuilt by application
     * code. Provider and setup hash are verified before any cursor is applied.
     * The default mismatch policy throws ResumeStateException; ResumeOptions can
     * instead discard the imported cursor and keep this context at a blank root.
     * This method performs no provider request, so an expired remote response is
     * detected only by the next AI call and its provider exception propagates.
     *
     * @param string $state JSON returned by exportState().
     * @param ResumeOptions|array{on_mismatch?: string} $options Resume mismatch policy.
     * @return $this
     * @throws ResumeStateException For malformed state or an incompatible state when configured to throw.
     * @throws InvalidArgumentException For invalid resume options.
     * @throws JsonException If the current setup cannot be encoded for hashing.
     * @throws LogicException While an operation is running.
     * @example $context->importState($_SESSION['ai_state']);
     * @example $context->importState($state, new ResumeOptions(onMismatch: ResumeOptions::ON_MISMATCH_RESTART));
     * @see exportState()
     * @see ResumeOptions
     */
    public function importState(string $state, ResumeOptions|array $options = []): self
    {
        $this->assertIdle();
        $resumeOptions = ResumeOptions::fromArray($options);
        $decoded = $this->decodeExportedState($state);

        // Format/provider/setup differences cannot safely reuse the provider cursor.
        if ($decoded['version'] !== self::STATE_VERSION) {
            return $this->handleResumeMismatch(
                $resumeOptions,
                'Unsupported AI context state version: ' . $decoded['version'] . '.',
            );
        }
        if ($decoded['provider'] !== self::STATE_PROVIDER) {
            return $this->handleResumeMismatch(
                $resumeOptions,
                'AI context state provider mismatch: expected '
                    . self::STATE_PROVIDER . ', got ' . $decoded['provider'] . '.',
            );
        }
        if (!hash_equals($this->setupHash(), $decoded['setupHash'])) {
            return $this->handleResumeMismatch(
                $resumeOptions,
                'AI context state setup mismatch; prepared prompts or tools changed.',
            );
        }

        $this->responseId = $decoded['state']['responseId'];
        $this->checkpoints = $decoded['state']['checkpoints'];

        return $this;
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
                $this->pendingPrompts = [];
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
     */
    private function registerContentItems(array $items): void
    {
        foreach ($items as $item) {
            if (!$item instanceof AiContent) {
                continue;
            }

            if (isset($this->contentById[$item->id])) {
                throw new InvalidArgumentException('Duplicate AI content ID in context: ' . $item->id);
            }

            $this->contentById[$item->id] = $item;
        }
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
        foreach ([...$contextItems, ...$this->pendingPrompts, ...$items] as $item) {
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

    /**
     * @return array{
     *   version: int,
     *   exportedAt: string,
     *   provider: string,
     *   setupHash: string,
     *   state: array{
     *     responseId: ?string,
     *     checkpoints: list<array{name: ?string, responseId: ?string}>
     *   }
     * }
     */
    private function decodeExportedState(string $state): array
    {
        try {
            $decoded = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ResumeStateException('Invalid AI context state JSON.', 0, $error);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ResumeStateException('AI context state must be a JSON object.');
        }
        foreach (['version', 'exportedAt', 'provider', 'setupHash', 'state'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new ResumeStateException('AI context state is missing field: ' . $key . '.');
            }
        }
        if (!is_int($decoded['version'])) {
            throw new ResumeStateException('AI context state version must be an integer.');
        }
        if (!is_string($decoded['exportedAt']) || trim($decoded['exportedAt']) === '') {
            throw new ResumeStateException('AI context state exportedAt must be a non-empty string.');
        }
        if (!is_string($decoded['provider']) || trim($decoded['provider']) === '') {
            throw new ResumeStateException('AI context state provider must be a non-empty string.');
        }
        if (!is_string($decoded['setupHash']) || preg_match('/^[a-f0-9]{64}$/', $decoded['setupHash']) !== 1) {
            throw new ResumeStateException('AI context state setupHash must be a SHA-256 hash.');
        }
        if (!is_array($decoded['state']) || array_is_list($decoded['state'])) {
            throw new ResumeStateException('AI context state payload must be a JSON object.');
        }

        $payload = $decoded['state'];
        if (!array_key_exists('responseId', $payload) || !array_key_exists('checkpoints', $payload)) {
            throw new ResumeStateException('AI context state payload requires responseId and checkpoints.');
        }
        if ($payload['responseId'] !== null && (!is_string($payload['responseId']) || trim($payload['responseId']) === '')) {
            throw new ResumeStateException('AI context state responseId must be a non-empty string or null.');
        }
        if (!is_array($payload['checkpoints']) || !array_is_list($payload['checkpoints'])) {
            throw new ResumeStateException('AI context state checkpoints must be a list.');
        }

        $checkpoints = [];
        foreach ($payload['checkpoints'] as $checkpoint) {
            if (
                !is_array($checkpoint)
                || !array_key_exists('name', $checkpoint)
                || !array_key_exists('responseId', $checkpoint)
            ) {
                throw new ResumeStateException('AI context state contains an invalid checkpoint.');
            }
            $name = $checkpoint['name'];
            $responseId = $checkpoint['responseId'];
            if ($name !== null && (!is_string($name) || trim($name) === '')) {
                throw new ResumeStateException('AI context checkpoint name must be a non-empty string or null.');
            }
            if ($responseId !== null && (!is_string($responseId) || trim($responseId) === '')) {
                throw new ResumeStateException('AI context checkpoint responseId must be a non-empty string or null.');
            }
            $checkpoints[] = ['name' => $name, 'responseId' => $responseId];
        }

        return [
            'version' => $decoded['version'],
            'exportedAt' => $decoded['exportedAt'],
            'provider' => $decoded['provider'],
            'setupHash' => $decoded['setupHash'],
            'state' => [
                'responseId' => $payload['responseId'],
                'checkpoints' => $checkpoints,
            ],
        ];
    }

    private function setupHash(): string
    {
        $items = [];
        foreach ($this->prompts as $item) {
            $items[] = [
                'class' => $item::class,
                'config' => $item instanceof ToolType
                    ? $item->toArray(self::STATE_PROVIDER)
                    : $item->toArray(),
            ];
        }

        return hash('sha256', Toolkit::jsonEncode($items));
    }

    private function handleResumeMismatch(ResumeOptions $options, string $message): self
    {
        if ($options->onMismatch === ResumeOptions::ON_MISMATCH_RESTART) {
            $this->responseId = null;
            $this->checkpoints = [];

            return $this;
        }

        throw new ResumeStateException($message);
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
