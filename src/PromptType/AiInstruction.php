<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use InvalidArgumentException;
use RuntimeException;

/**
 * Explicit trusted model instructions.
 *
 * External or user-controlled data belongs in AiContent, never here.
 */
final readonly class AiInstruction implements PromptType
{
    public function __construct(public string $text)
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('AI instruction must not be empty.');
        }
    }

    public static function fromFile(string $fileName): self
    {
        $content = @file_get_contents($fileName);
        if ($content === false) {
            throw new RuntimeException('Could not read AI instruction file: ' . $fileName);
        }
        return new self($content);
    }

    public static function fromStream(mixed $stream): self
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('AI instruction stream must be a resource.');
        }
        $content = stream_get_contents($stream);
        if ($content === false) {
            throw new RuntimeException('Could not read AI instruction stream.');
        }
        return new self($content);
    }

    public function type(): string { return 'system'; }

    public function toArray(): array
    {
        return ['type' => 'system', 'text' => $this->text];
    }
}
