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

    protected function __construct(
        public string $rawData,
        public ?string $fileName = null,
        public ?string $description = null,
        ?AiContext $context = null,
    ) {
        $this->fileName = self::normalizeFileName($fileName);
        $this->description = self::normalizeOptionalText($description, 'description');
        $this->ai_set_context($context === null
            ? new AiContext(prompts: [$this])
            : $context->withPrepared([$this]));
    }

    abstract public function toPromptType(): PromptType;

    public function type(): string { return 'content'; }

    public function size(): int { return strlen($this->rawData); }

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
