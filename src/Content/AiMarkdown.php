<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;

readonly class AiMarkdown extends AiText
{
    public function __construct(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ) {
        AiDocument::__construct($rawData, $fileName, 'text/markdown', $description, $context);
    }

    public static function fromRaw(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        return new static($rawData, $fileName, $description, $context);
    }

    public static function fromFile(
        string $path,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        [$data, $fileName] = self::readFile($path);

        return new static($data, $fileName, $description, $context);
    }

    public static function fromStream(
        mixed $stream,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        return new static(self::readStream($stream), $fileName, $description, $context);
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

    protected function recreate(string $rawData, ?AiContext $context = null): static
    {
        return new static($rawData, $this->fileName, $this->description, $context);
    }
}
