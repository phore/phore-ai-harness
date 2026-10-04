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
    ) {
        $resolved = $contentType instanceof ContentType
            ? $contentType
            : ($contentType !== null
                ? ContentType::fromMimeType($contentType)
                : ($fileName !== null ? ContentType::fromFileName($fileName) : null));

        if ($resolved === null) {
            throw new \InvalidArgumentException(
                'AiDocument requires either a supported content type or a file name with a supported extension.',
            );
        }

        $this->contentType = $resolved->mimeType();
        parent::__construct($rawData, $fileName, $description, $context);
    }

    /**
     * Create a document from already loaded bytes.
     *
     * @param string $rawData Raw document bytes.
     * @param string|null $fileName Optional source filename used for type detection.
     * @param ContentType|string|null $contentType Explicit supported type; wins over file extension.
     * @param string|null $description Trusted application metadata describing the source.
     * @param AiContext|null $context Optional idle context to extend with this document.
     * @return static Immutable document.
     * @example AiDocument::fromRaw($bytes, fileName: 'cv.pdf');
     * @see AiDocumentFactory::fromRaw()
     */
    public static function fromRaw(
        string $rawData,
        ?string $fileName = null,
        ContentType|string|null $contentType = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        return new static($rawData, $fileName, $contentType, $description, $context);
    }

    /**
     * Load a document from a readable file and infer its supported type by extension.
     *
     * @param string $path Path to the source file.
     * @param string|null $description Trusted application metadata.
     * @param AiContext|null $context Optional idle context.
     * @return static Loaded immutable document.
     * @throws \RuntimeException When the file cannot be read.
     * @throws \InvalidArgumentException When the extension is unsupported.
     * @example AiDocument::fromFile('/tmp/cv.pdf');
     * @see AiDocumentFactory::fromFile()
     */
    public static function fromFile(
        string $path,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        [$data, $fileName] = self::readFile($path);

        return new static($data, $fileName, ContentType::fromFileName($fileName), $description, $context);
    }

    /**
     * Create a document from a readable stream.
     *
     * @param mixed $stream Readable PHP stream resource.
     * @param string|null $fileName Optional filename for type detection.
     * @param ContentType|string|null $contentType Explicit supported content type.
     * @param string|null $description Trusted application metadata.
     * @param AiContext|null $context Optional idle context.
     * @return static Immutable document containing the consumed stream bytes.
     * @example AiDocument::fromStream($stream, fileName: 'cv.pdf');
     * @see fromRaw()
     */
    public static function fromStream(
        mixed $stream,
        ?string $fileName = null,
        ContentType|string|null $contentType = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        return new static(self::readStream($stream), $fileName, $contentType, $description, $context);
    }

    public function toPromptType(): PromptType
    {
        return new FilePrompt(
            $this->fileName ?? ('document.' . ContentType::fromMimeType($this->contentType)->extension()),
            $this->rawData,
            $this->contentType,
            instructions: $this->description,
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

    protected function recreate(string $rawData, ?AiContext $context = null): static
    {
        return new static($rawData, $this->fileName, $this->contentType, $this->description, $context);
    }
}
