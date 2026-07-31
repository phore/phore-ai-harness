<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;
use RuntimeException;

final readonly class FilePrompt implements PromptType
{
    public ?string $alias;

    public ?string $instructions;

    public ?string $type;

    public function __construct(
        public string $fileName,
        public string $content,
        public string $contentType = 'application/octet-stream',
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'FilePrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'FilePrompt');
        $this->type = PromptMetadata::validateContentType($type, 'FilePrompt');
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
     * @return array{type: string, fileName: string, content: string, contentType: string, alias?: string, instructions?: string, contentFormat?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'fileName' => $this->fileName,
            'content' => $this->content,
            'contentType' => $this->contentType,
        ];

        PromptMetadata::addToArray($array, $this->alias, $this->instructions, $this->type);

        return $array;
    }

}
