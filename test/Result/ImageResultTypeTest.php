<?php

declare(strict_types=1);

use Phore\AiHarness\Result\ImageResultType;
use PHPUnit\Framework\TestCase;

final class ImageResultTypeTest extends TestCase
{
    public function testStoresImageDataContentTypeAndFileExtension(): void
    {
        $result = new ImageResultType('image-binary', 'image/png');

        self::assertSame('image-binary', $result->data);
        self::assertSame('image/png', $result->contentType);
        self::assertSame('png', $result->fileExtension);
    }

    public function testSaveToFileWritesBinaryContentAndCreatesDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/phore-ai-image-result-' . bin2hex(random_bytes(4));
        $fileName = $directory . '/image.png';

        (new ImageResultType('binary', 'image/png'))->saveToFile($fileName);

        self::assertFileExists($fileName);
        self::assertSame('binary', file_get_contents($fileName));
    }
}
