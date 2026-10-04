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

    protected function __construct(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ) {
        $this->rawData = $rawData;
        $this->fileName = self::normalizeFileName($fileName);
        $this->description = self::normalizeOptionalText($description, 'description');
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
     * context clones that context and attaches this content to the branch. This
     * also works for an already started conversation; the content is sent once
     * with the next request on the cloned branch.
     *
     * @param AiContext|null $context Existing context or null for a fresh context.
     * @return static New content instance; the original object stays unchanged.
     * @example $detached = $image->withContext();
     * @see AiContext::withSource()
     */
    public function withContext(?AiContext $context = null): static
    {
        return $this->recreate($this->rawData, $context);
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
            'fileName' => $this->fileName,
            'description' => $this->description,
            'bytes' => $this->size(),
            'sha256' => hash('sha256', $this->rawData),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Recreate this concrete immutable content object with raw data and context.
     *
     * @param string $rawData Raw content for the new instance.
     * @param AiContext|null $context Context to bind, or null for a fresh context.
     * @return static New concrete content object.
     * @see withContext()
     */
    abstract protected function recreate(string $rawData, ?AiContext $context = null): static;

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
}
