<?php

declare(strict_types=1);

use Phore\AiHarness\Helper\DataUrl;
use PHPUnit\Framework\TestCase;

final class DataUrlTest extends TestCase
{
    public function testConvertsBinaryDataToBase64DataUrl(): void
    {
        $dataUrl = new DataUrl('binary', 'image/png');

        self::assertSame('data:image/png;base64,' . base64_encode('binary'), $dataUrl->toString());
    }

    public function testLoadsBase64DataUrlString(): void
    {
        $dataUrl = DataUrl::loadString('data:image/webp;base64,' . base64_encode('webp'));

        self::assertSame('webp', $dataUrl->data);
        self::assertSame('image/webp', $dataUrl->contentType);
    }

    public function testTryLoadStringReturnsNullForInvalidDataUrl(): void
    {
        self::assertNull(DataUrl::tryLoadString('not-a-data-url'));
    }

    public function testMapsContentTypeToFileExtensionAndOpenAiOutputFormat(): void
    {
        self::assertSame('png', DataUrl::fileExtensionFromContentType('image/png'));
        self::assertSame('jpg', DataUrl::fileExtensionFromContentType('image/jpeg'));
        self::assertSame('webp', DataUrl::fileExtensionFromContentType('image/webp'));
        self::assertSame('jpeg', DataUrl::openAiImageOutputFormatFromContentType('image/jpeg'));
        self::assertSame('webp', DataUrl::openAiImageOutputFormatFromContentType('image/webp'));
        self::assertSame('png', DataUrl::openAiImageOutputFormatFromContentType('application/octet-stream'));
    }

    public function testLoadsFile(): void
    {
        $fileName = tempnam(sys_get_temp_dir(), 'phore-ai-data-url-');
        self::assertIsString($fileName);
        file_put_contents($fileName, 'file-data');

        $dataUrl = DataUrl::fromFile($fileName, 'text/plain');

        self::assertSame('file-data', $dataUrl->data);
        self::assertSame('text/plain', $dataUrl->contentType);
    }
}
