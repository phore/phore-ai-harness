<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;

/**
 * Supported OpenAI-facing MIME type with deterministic file-extension mapping.
 *
 * The table intentionally mirrors formats handled by the OpenAI adapters in
 * this package instead of acting as a general-purpose MIME database.
 */
final readonly class ContentType
{
    /** @var array<string, list<string>> */
    private const MIME_TO_EXTENSIONS = [
        'application/json' => ['json'],
        'application/msword' => ['doc'],
        'application/pdf' => ['pdf'],
        'application/typescript' => ['ts'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/xml' => ['xml'],
        'application/x-sh' => ['sh'],
        'text/csv' => ['csv'],
        'text/css' => ['css'],
        'text/html' => ['html', 'htm'],
        'text/javascript' => ['js'],
        'text/markdown' => ['md', 'markdown'],
        'text/plain' => ['txt'],
        'text/x-c' => ['c'],
        'text/x-c++' => ['cpp', 'cc'],
        'text/x-csharp' => ['cs'],
        'text/x-golang' => ['go'],
        'text/x-java-source' => ['java'],
        'text/x-php' => ['php'],
        'text/x-python' => ['py'],
        'text/x-ruby' => ['rb'],
        'text/x-tex' => ['tex'],
        'text/yaml' => ['yaml', 'yml'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
    ];

    /** @var list<string> */
    private const FILE_MIME_TYPES = [
        'application/json',
        'application/msword',
        'application/pdf',
        'application/typescript',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/xml',
        'application/x-sh',
        'text/csv',
        'text/css',
        'text/html',
        'text/javascript',
        'text/markdown',
        'text/plain',
        'text/x-c',
        'text/x-c++',
        'text/x-csharp',
        'text/x-golang',
        'text/x-java-source',
        'text/x-php',
        'text/x-python',
        'text/x-ruby',
        'text/x-tex',
        'text/yaml',
    ];

    private function __construct(private string $mimeType)
    {
    }

    /**
     * Resolve a supported MIME type.
     *
     * @throws InvalidArgumentException When the MIME type is not supported.
     * @example ContentType::fromMimeType('application/pdf')->extension();
     * @see fromExtension()
     */
    public static function fromMimeType(string $mimeType): self
    {
        $mimeType = strtolower(trim($mimeType));
        if (!isset(self::MIME_TO_EXTENSIONS[$mimeType])) {
            throw new InvalidArgumentException('Unsupported AI content type: ' . $mimeType);
        }

        return new self($mimeType);
    }

    /**
     * Resolve a supported MIME type from a file extension.
     *
     * @throws InvalidArgumentException When no supported mapping exists.
     * @example ContentType::fromExtension('.pdf')->mimeType();
     * @see fromFileName()
     */
    public static function fromExtension(string $extension): self
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        foreach (self::MIME_TO_EXTENSIONS as $mimeType => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return new self($mimeType);
            }
        }

        throw new InvalidArgumentException('Unsupported AI file extension: ' . $extension);
    }

    /**
     * Resolve a supported MIME type from a file name.
     *
     * @throws InvalidArgumentException When the file has no supported extension.
     * @example ContentType::fromFileName('attachment.pdf');
     * @see fromExtension()
     */
    public static function fromFileName(string $fileName): self
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        if ($extension === '') {
            throw new InvalidArgumentException('AI content file name has no extension: ' . $fileName);
        }

        return self::fromExtension($extension);
    }

    /**
     * Check whether a MIME type is part of the explicit harness mapping.
     *
     * @example if (ContentType::isSupported('application/pdf')) { ... }
     * @see fromMimeType()
     */
    public static function isSupported(string $mimeType): bool
    {
        return isset(self::MIME_TO_EXTENSIONS[strtolower(trim($mimeType))]);
    }

    /**
     * Return MIME types accepted by the OpenAI input_file adapter.
     *
     * @return list<string>
     * @example $types = ContentType::fileMimeTypes();
     * @see \Phore\AiHarness\Client\OpenAI\OpenAiPromptToContentConverter
     */
    public static function fileMimeTypes(): array
    {
        return self::FILE_MIME_TYPES;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function extension(): string
    {
        return self::MIME_TO_EXTENSIONS[$this->mimeType][0];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return self::MIME_TO_EXTENSIONS[$this->mimeType];
    }

    public function __toString(): string
    {
        return $this->mimeType;
    }
}
