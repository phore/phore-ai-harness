<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;

final readonly class AiVideo extends AiDocument
{
    public function __construct(
        string $rawData,
        ?string $fileName = null,
        ContentType|string|null $contentType = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        parent::__construct(
            $rawData,
            $fileName,
            $contentType,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
        if (!str_starts_with($this->contentType, 'video/')) {
            throw new \InvalidArgumentException('AiVideo requires a video content type.');
        }
    }

    public static function fromRaw(
        string $rawData,
        ?string $fileName = null,
        ContentType|string|null $contentType = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        return new self(
            $rawData,
            $fileName,
            $contentType,
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
    ): self {
        [$data, $fileName] = self::readFile($path);

        return new self(
            $data,
            $fileName,
            ContentType::fromFileName($fileName),
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
        ContentType|string|null $contentType = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        return new self(
            self::readStream($stream),
            $fileName,
            $contentType,
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
            $this->fileName ?? ('video.' . ContentType::fromMimeType($this->contentType)->extension()),
            $this->rawData,
            $this->contentType,
            alias: $this->id,
            instructions: $this->promptInstructions(),
            type: 'video',
            allowInstructions: false,
        );
    }

    protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static {
        return new self(
            $rawData,
            $this->fileName,
            $this->contentType,
            $this->description,
            $context,
            $id,
            $this->aliases,
            $this->instructions,
        );
    }
}
