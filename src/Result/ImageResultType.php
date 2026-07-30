<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

use Phore\AiHarness\Helper\DataUrl;
use RuntimeException;

final readonly class ImageResultType
{
    public string $fileExtension;

    public function __construct(
        public string $data,
        public string $contentType,
        ?string $fileExtension = null,
    ) {
        $this->fileExtension = $fileExtension ?? DataUrl::fileExtensionFromContentType($contentType);
    }

    public function saveToFile(string $fileName): void
    {
        $directory = dirname($fileName);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create directory for image output: ' . $directory);
            }
        }

        if (file_put_contents($fileName, $this->data) === false) {
            throw new RuntimeException('Could not write image output file: ' . $fileName);
        }
    }
}
