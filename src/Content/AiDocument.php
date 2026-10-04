<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\AiInstruction;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;

/**
 * Primary abstraction for AI-processable file-like content.
 */
readonly class AiDocument extends AiContent
{
    public string $contentType;

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
        if ($contentType instanceof ContentType) {
            $resolved = $contentType->mimeType();
        } elseif ($contentType !== null) {
            $resolved = strtolower(trim($contentType));
            if ($resolved === '') {
                throw new \InvalidArgumentException('AI document content type must not be empty.');
            }
        } elseif ($fileName !== null) {
            $resolved = ContentType::fromFileName($fileName)->mimeType();
        } else {
            throw new \InvalidArgumentException(
                'AiDocument requires either a content type or a file name with a supported extension.',
            );
        }

        $this->contentType = $resolved;
        parent::__construct(
            $rawData,
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
        $fileName = $this->fileName;
        if ($fileName === null) {
            $extension = ContentType::isSupported($this->contentType)
                ? ContentType::fromMimeType($this->contentType)->extension()
                : 'bin';
            $fileName = 'document.' . $extension;
        }

        return new FilePrompt(
            $fileName,
            $this->rawData,
            $this->contentType,
            alias: $this->id,
            instructions: $this->promptInstructions(),
            allowInstructions: false,
        );
    }

    /**
     * Ask the model to extract meaningful text from this document.
     *
     * @return string Extracted text in reading order.
     * @example $plainText = $document->extractText();
     * @see AiContext::text()
     */
    public function extractText(): string
    {
        return $this->ai_text(new AiInstruction(
            'Extract the complete meaningful text from the supplied document. Preserve reading order and do not invent missing text.',
        ));
    }

    protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static {
        return new static(
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
