<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Helper\DataUrl;
use Phore\AiHarness\PromptType\AiInstruction;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;

final readonly class AiDocument extends AiContent
{
    public function __construct(
        string $rawData,
        ?string $fileName = null,
        public ?string $contentType = null,
        ?string $description = null,
        ?AiContext $context = null,
    ) {
        parent::__construct($rawData, $fileName, $description, $context);
        $this->contentType = $contentType
            ?? ($this->fileName !== null ? DataUrl::detectContentType($this->fileName) : null)
            ?? 'application/octet-stream';
    }

    public static function fromRaw(string $rawData, ?string $fileName = null, ?string $contentType = null, ?string $description = null, ?AiContext $context = null): self
    {
        return new self($rawData, $fileName, $contentType, $description, $context);
    }

    public static function fromFile(string $path, ?string $description = null, ?AiContext $context = null): self
    {
        [$data, $fileName] = self::readFile($path);
        return new self($data, $fileName, DataUrl::detectContentType($path), $description, $context);
    }

    public static function fromStream(mixed $stream, ?string $fileName = null, ?string $contentType = null, ?string $description = null, ?AiContext $context = null): self
    {
        return new self(self::readStream($stream), $fileName, $contentType, $description, $context);
    }

    public function toPromptType(): PromptType
    {
        return new FilePrompt(
            $this->fileName ?? 'document.bin',
            $this->rawData,
            $this->contentType,
            instructions: $this->description,
            allowInstructions: false,
        );
    }

    public function extractText(): string
    {
        return $this->ai_text(new AiInstruction(
            'Extract the complete meaningful text from the supplied document. Preserve reading order and do not invent missing text.'
        ));
    }
}
