<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiContextTrait;
use Phore\AiHarness\PromptType\PromptType;
use RuntimeException;

/**
 * Immutable source material. AiContent is always data, never instructions.
 */
abstract readonly class AiContent implements PromptType
{
    use AiContextTrait;

    public string $rawData;
    public ?string $fileName;
    public ?string $description;
    public string $id;

    /** @var list<string> */
    public array $aliases;

    public string $instructions;

    protected function __construct(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        $this->rawData = $rawData;
        $this->fileName = self::normalizeFileName($fileName);
        $this->description = self::normalizeOptionalText($description, 'description');
        $this->id = self::normalizeId($id);
        $this->aliases = self::normalizeAliases($aliases);
        $this->instructions = trim($instructions);

        $this->ai_set_context($context === null
            ? new AiContext(prompts: [$this])
            : $context->withSource([$this]));
    }

    abstract public function toPromptType(): PromptType;

    /**
     * Return the same immutable content bound to another AI context.
     *
     * Passing null deliberately detaches the content from its current conversation
     * and creates a fresh context containing only this content. Passing an existing
     * context clones that context and attaches this content to the branch. The
     * content ID is preserved because the logical content itself is unchanged.
     *
     * @param AiContext|null $context Existing context or null for a fresh context.
     * @return static New content instance; the original object stays unchanged.
     * @example $detached = $image->withContext();
     * @see AiContext::withSource()
     */
    public function withContext(?AiContext $context = null): static
    {
        return $this->recreate($this->rawData, $context, $this->id);
    }

    /**
     * Return the immutable unique ID used to address this content in a context.
     *
     * @return string Stable ID for this content object and context clones.
     * @example $id = $document->getId();
     * @see AiContext::getContentById()
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Return optional human-friendly aliases. Aliases are not required to be
     * unique across content objects and are intended for prompt references.
     *
     * @return list<string>
     * @example $aliases = $document->getAliases();
     * @see AiContext::queryContent()
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Return document-specific handling instructions supplied by the application.
     *
     * @return string Empty string when no special handling was configured.
     * @example $instructions = $document->getInstructions();
     * @see toPromptType()
     */
    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function type(): string
    {
        return 'content';
    }

    public function size(): int
    {
        return strlen($this->rawData);
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => 'content',
            'contentClass' => static::class,
            'id' => $this->id,
            'aliases' => $this->aliases,
            'fileName' => $this->fileName,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'bytes' => $this->size(),
            'sha256' => hash('sha256', $this->rawData),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Recreate this concrete immutable content object with raw data and context.
     *
     * Passing an ID preserves identity, which is used by withContext(). Omitting
     * it creates a new identity for transformed content such as an edited copy.
     *
     * @param string $rawData Raw content for the new instance.
     * @param AiContext|null $context Context to bind, or null for a fresh context.
     * @param string|null $id Existing ID to preserve or null for a generated ID.
     * @return static New concrete content object.
     * @see withContext()
     */
    abstract protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static;

    /**
     * Build trusted prompt metadata for this content.
     *
     * @return string|null Instructions metadata or null when no metadata exists.
     */
    protected function promptInstructions(): ?string
    {
        $parts = [];

        if ($this->description !== null) {
            $parts[] = 'Description: ' . $this->description;
        }
        if ($this->aliases !== []) {
            $parts[] = 'Aliases: ' . implode(', ', $this->aliases);
        }
        if ($this->instructions !== '') {
            $parts[] = 'Handling instructions: ' . $this->instructions;
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    protected static function readFile(string $path): array
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Could not read AI content file: ' . $path);
        }

        return [$data, self::normalizeFileName($path)];
    }

    protected static function readStream(mixed $stream): string
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('AI content stream must be a resource.');
        }

        $data = stream_get_contents($stream);
        if ($data === false) {
            throw new RuntimeException('Could not read AI content stream.');
        }

        return $data;
    }

    protected static function normalizeFileName(?string $fileName): ?string
    {
        if ($fileName === null) {
            return null;
        }

        $fileName = trim($fileName);
        if ($fileName === '') {
            throw new InvalidArgumentException('AI content file name must not be empty.');
        }

        return basename(str_replace('\\', '/', $fileName));
    }

    protected static function normalizeOptionalText(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('AI content ' . $label . ' must not be empty.');
        }

        return $value;
    }

    private static function normalizeId(?string $id): string
    {
        if ($id === null) {
            return 'content_' . bin2hex(random_bytes(8));
        }

        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('AI content ID must not be empty.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $id) !== 1) {
            throw new InvalidArgumentException(
                'AI content ID may contain only letters, digits, dot, underscore, colon and dash.',
            );
        }

        return $id;
    }

    /**
     * @param array<int, mixed> $aliases
     * @return list<string>
     */
    private static function normalizeAliases(array $aliases): array
    {
        if (!array_is_list($aliases)) {
            throw new InvalidArgumentException('AI content aliases must be a list of strings.');
        }

        $normalized = [];
        $seen = [];
        foreach ($aliases as $alias) {
            if (!is_string($alias) || trim($alias) === '') {
                throw new InvalidArgumentException('AI content aliases must contain non-empty strings.');
            }

            $alias = trim($alias);
            if (isset($seen[$alias])) {
                throw new InvalidArgumentException('AI content aliases must not contain duplicates.');
            }

            $seen[$alias] = true;
            $normalized[] = $alias;
        }

        return $normalized;
    }
}
