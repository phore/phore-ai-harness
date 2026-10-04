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
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        AiDocument::__construct(
            $rawData,
            $fileName,
            'text/markdown',
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public static function fromRaw(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): static {
        return new static(
            $rawData,
            $fileName,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public static function fromFile(
        string $path,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): static {
        [$data, $fileName] = self::readFile($path);

        return new static(
            $data,
            $fileName,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public static function fromStream(
        mixed $stream,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): static {
        return new static(
            self::readStream($stream),
            $fileName,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public function toPromptType(): PromptType
    {
        return new FilePrompt(
            $this->fileName ?? 'content.md',
            $this->rawData,
            'text/markdown',
            alias: $this->id,
            instructions: $this->promptInstructions(),
            type: 'markdown',
            allowInstructions: false,
        );
    }

    protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static {
        return new static(
            $rawData,
            $this->fileName,
            $this->description,
            $context,
            $id,
            $this->aliases,
            $this->instructions,
        );
    }
}
