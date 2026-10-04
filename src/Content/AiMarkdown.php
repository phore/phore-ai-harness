<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;

final readonly class AiMarkdown extends AiContent
{
    public static function fromRaw(string $rawData, ?string $fileName = null, ?string $description = null, ?AiContext $context = null): self
    {
        return new self($rawData, $fileName, $description, $context);
    }

    public static function fromFile(string $path, ?string $description = null, ?AiContext $context = null): self
    {
        [$data, $fileName] = self::readFile($path);
        return new self($data, $fileName, $description, $context);
    }

    public static function fromStream(mixed $stream, ?string $fileName = null, ?string $description = null, ?AiContext $context = null): self
    {
        return new self(self::readStream($stream), $fileName, $description, $context);
    }

    public function toPromptType(): PromptType
    {
        return new FilePrompt(
            $this->fileName ?? 'content.md',
            $this->rawData,
            'text/markdown',
            instructions: $this->description,
            type: 'markdown',
            allowInstructions: false,
        );
    }
}
