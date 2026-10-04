<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;

/**
 * Generic immutable text document with AI editing operations.
 */
readonly class AiText extends AiDocument
{
    public function __construct(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ) {
        parent::__construct($rawData, $fileName, 'text/plain', $description, $context);
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

    /**
     * Edit the complete text and return a new immutable text document.
     *
     * @param string $instruction Requested transformation.
     * @return static Edited text in a fresh content object.
     * @example $short = $text->edit('Shorten this to two sentences.');
     * @see AiContext::text()
     */
    public function edit(string $instruction): static
    {
        $edited = $this->ai_text($instruction, input: $this->rawData);

        return $this->recreate($edited, $this->ai_get_context());
    }

    public function toPromptType(): PromptType
    {
        return new FilePrompt(
            $this->fileName ?? 'content.txt',
            $this->rawData,
            $this->contentType,
            instructions: $this->description,
            type: 'text',
            allowInstructions: false,
        );
    }

    public function __toString(): string
    {
        return $this->rawData;
    }

    protected function recreate(string $rawData, ?AiContext $context = null): static
    {
        return new static($rawData, $this->fileName, $this->description, $context);
    }
}
