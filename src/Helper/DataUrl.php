<?php

declare(strict_types=1);

namespace Phore\AiHarness\Helper;

use InvalidArgumentException;

final readonly class DataUrl
{
    public function __construct(
        public string $data,
        public string $contentType,
    ) {
        if ($contentType === '') {
            throw new InvalidArgumentException('DataUrl content type must not be empty.');
        }
    }

    public static function fromFile(string $fileName, ?string $contentType = null): self
    {
        $data = @file_get_contents($fileName);
        if ($data === false) {
            throw new InvalidArgumentException('Could not read data URL file: ' . $fileName);
        }

        return new self($data, $contentType ?? self::detectContentType($fileName) ?? 'application/octet-stream');
    }

    public static function loadString(string $dataUrl, string $defaultContentType = 'application/octet-stream'): self
    {
        $dataUrl = self::tryLoadString($dataUrl, $defaultContentType);
        if ($dataUrl === null) {
            throw new InvalidArgumentException('Invalid base64 data URL.');
        }

        return $dataUrl;
    }

    public static function tryLoadString(string $dataUrl, string $defaultContentType = 'application/octet-stream'): ?self
    {
        if (preg_match('/^data:([^;,]+)?;base64,(.*)$/s', $dataUrl, $matches) !== 1) {
            return null;
        }

        $data = base64_decode($matches[2], true);
        if ($data === false) {
            return null;
        }

        return new self($data, $matches[1] !== '' ? $matches[1] : $defaultContentType);
    }

    public function toString(): string
    {
        return 'data:' . $this->contentType . ';base64,' . base64_encode($this->data);
    }

    public static function fileExtensionFromContentType(string $contentType): string
    {
        return match (strtolower($contentType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'text/plain' => 'txt',
            default => 'png',
        };
    }

    public static function openAiImageOutputFormatFromContentType(string $contentType): string
    {
        return match (strtolower($contentType)) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    public static function detectContentType(string $fileName): ?string
    {
        if (function_exists('mime_content_type')) {
            $contentType = @mime_content_type($fileName);
            if (is_string($contentType) && $contentType !== '') {
                return $contentType;
            }
        }

        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
