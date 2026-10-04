<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;

/**
 * Supported OpenAI-facing MIME type with deterministic file-extension mapping.
 *
 * The table is intentionally small and explicit. It represents content types
 * handled by the harness instead of trying to be a general-purpose MIME database.
 */
final readonly class ContentType
{
    /**
     * @var array<string, list<string>>
     */
    private const MIME_TO_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'application/json' => ['json'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'text/plain' => ['txt'],
        'text/markdown' => ['md', 'markdown'],
        'text/csv' => ['csv'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/mp4' => ['m4a'],
        'audio/ogg' => ['ogg'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
    ];

    private function __construct(private string $mimeType)
    {
    }

    /**
     * Resolve a supported MIME type.
     *
     * @param string $mimeType MIME type such as application/pdf.
     * @return self Normalized supported content type.
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
     * @param string $extension Extension with or without a leading dot.
     * @return self Matching supported content type.
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
     * @param string $fileName File name containing a supported extension.
     * @return self Matching supported content type.
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
     * @param string $mimeType MIME type to check.
     * @return bool True when the type can be resolved by this class.
     * @example if (ContentType::isSupported('application/pdf')) { ... }
     * @see fromMimeType()
     */
    public static function isSupported(string $mimeType): bool
    {
        return isset(self::MIME_TO_EXTENSIONS[strtolower(trim($mimeType))]);
    }

    /**
     * Return the normalized MIME type.
     *
     * @return string MIME type.
     * @example $type->mimeType();
     * @see extension()
     */
    public function mimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Return the preferred extension for this MIME type.
     *
     * @return string Preferred extension without a dot.
     * @example $type->extension();
     * @see extensions()
     */
    public function extension(): string
    {
        return self::MIME_TO_EXTENSIONS[$this->mimeType][0];
    }

    /**
     * Return every known extension for this MIME type.
     *
     * @return list<string> Extensions without leading dots.
     * @example ContentType::fromMimeType('image/jpeg')->extensions();
     * @see extension()
     */
    public function extensions(): array
    {
        return self::MIME_TO_EXTENSIONS[$this->mimeType];
    }

    public function __toString(): string
    {
        return $this->mimeType;
    }
}
