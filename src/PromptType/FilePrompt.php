<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;
use RuntimeException;

final readonly class FilePrompt implements PromptType
{
    public function __construct(
        public string $fileName,
        public string $content,
        public string $contentType = 'application/octet-stream',
    ) {
    }

    public static function fromFile(string $fileName): self
    {
        $content = @file_get_contents($fileName);
        if ($content === false) {
            throw new RuntimeException('Could not read prompt file: ' . $fileName);
        }

        return new self($fileName, $content, DataUrl::detectContentType($fileName) ?? 'application/octet-stream');
    }

    public function type(): string
    {
        return 'file';
    }

    /**
     * @return array{type: string, fileName: string, content: string, contentType: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'fileName' => $this->fileName,
            'content' => $this->content,
            'contentType' => $this->contentType,
        ];
    }

}
