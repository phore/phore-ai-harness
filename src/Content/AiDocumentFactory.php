<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;

/**
 * Preferred creation entry point for AiDocument instances.
 */
final class AiDocumentFactory
{
    /**
     * @var array<string, callable(string, ?string, string, ?string, ?AiContext): AiDocument>
     */
    private array $factories = [];

    /**
     * @var array<string, string>
     */
    private array $customExtensions = [];

    /**
     * Register or override a document type resolver.
     *
     * User registrations take precedence over the built-in mappings. Optional
     * extensions let fromFile() and fromRaw(fileName: ...) resolve custom MIME types.
     *
     * @param string $contentType MIME type handled by the callback.
     * @param callable(string, ?string, string, ?string, ?AiContext): AiDocument $factory Factory callback.
     * @param list<string> $extensions Optional filename extensions without dots.
     * @return $this Same factory instance for fluent setup.
     * @example $factory->register('application/x-note', fn ($raw, $name, $type, $description, $context) => new CustomNote($raw, $name, $description, $context), ['note']);
     * @see fromRaw()
     */
    public function register(string $contentType, callable $factory, array $extensions = []): self
    {
        $contentType = strtolower(trim($contentType));
        if ($contentType === '') {
            throw new InvalidArgumentException('Registered AI document content type must not be empty.');
        }

        $this->factories[$contentType] = $factory;
        foreach ($extensions as $extension) {
            $extension = strtolower(ltrim(trim($extension), '.'));
            if ($extension === '') {
                throw new InvalidArgumentException('Registered AI document extension must not be empty.');
            }
            $this->customExtensions[$extension] = $contentType;
        }

        return $this;
    }

    /**
     * Load a file and return the most specific registered AiDocument.
     *
     * @param string $path Readable source file.
     * @param string|null $description Trusted application metadata.
     * @param AiContext|null $context Optional idle AI context.
     * @return AiDocument Specialized document selected from the filename extension.
     * @throws \RuntimeException When the file cannot be read.
     * @throws InvalidArgumentException When the extension is unsupported.
     * @example $document = (new AiDocumentFactory())->fromFile('/tmp/mail.pdf');
     * @see fromRaw()
     */
    public function fromFile(
        string $path,
        ?string $description = null,
        ?AiContext $context = null,
    ): AiDocument {
        $rawData = @file_get_contents($path);
        if ($rawData === false) {
            throw new \RuntimeException('Could not read AI document file: ' . $path);
        }

        $fileName = basename(str_replace('\\', '/', $path));
        $contentType = $this->resolveContentType(null, $fileName);

        return $this->create($rawData, $fileName, $contentType, $description, $context);
    }

    /**
     * Create a document from raw bytes and either MIME type or filename.
     *
     * An explicit content type wins. Without one, a filename with a supported or
     * custom-registered extension is required.
     *
     * @param string $rawData Raw source bytes.
     * @param ContentType|string|null $contentType Explicit content type.
     * @param string|null $fileName Optional filename used for type detection.
     * @param string|null $description Trusted application metadata.
     * @param AiContext|null $context Optional idle AI context.
     * @return AiDocument Specialized document instance.
     * @throws InvalidArgumentException When neither type nor resolvable filename is available.
     * @example $document = (new AiDocumentFactory())->fromRaw($bytes, fileName: 'attachment.pdf');
     * @see register()
     */
    public function fromRaw(
        string $rawData,
        ContentType|string|null $contentType = null,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): AiDocument {
        $mimeType = $this->resolveContentType($contentType, $fileName);

        return $this->create($rawData, $fileName, $mimeType, $description, $context);
    }

    private function resolveContentType(ContentType|string|null $contentType, ?string $fileName): string
    {
        if ($contentType instanceof ContentType) {
            return $contentType->mimeType();
        }
        if ($contentType !== null) {
            $contentType = strtolower(trim($contentType));
            if (isset($this->factories[$contentType]) || ContentType::isSupported($contentType)) {
                return $contentType;
            }
            throw new InvalidArgumentException('Unsupported AI document content type: ' . $contentType);
        }
        if ($fileName === null) {
            throw new InvalidArgumentException(
                'AI document raw data requires either contentType or a filename with a supported extension.',
            );
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (isset($this->customExtensions[$extension])) {
            return $this->customExtensions[$extension];
        }

        return ContentType::fromFileName($fileName)->mimeType();
    }

    private function create(
        string $rawData,
        ?string $fileName,
        string $contentType,
        ?string $description,
        ?AiContext $context,
    ): AiDocument {
        if (isset($this->factories[$contentType])) {
            $document = ($this->factories[$contentType])(
                $rawData,
                $fileName,
                $contentType,
                $description,
                $context,
            );
            if (!$document instanceof AiDocument) {
                throw new InvalidArgumentException('Registered AI document factory must return AiDocument.');
            }

            return $document;
        }

        return match (true) {
            $contentType === 'text/markdown' => AiMarkdown::fromRaw($rawData, $fileName, $description, $context),
            $contentType === 'text/plain' => AiText::fromRaw($rawData, $fileName, $description, $context),
            str_starts_with($contentType, 'image/') => AiImage::fromRaw($rawData, $fileName, $description, $context),
            str_starts_with($contentType, 'audio/') => AiAudio::fromRaw(
                $rawData,
                ContentType::fromMimeType($contentType)->extension(),
                $fileName,
                $description,
                $context,
            ),
            str_starts_with($contentType, 'video/') => AiVideo::fromRaw(
                $rawData,
                $fileName,
                $contentType,
                $description,
                $context,
            ),
            default => new AiDocument($rawData, $fileName, $contentType, $description, $context),
        };
    }
}
