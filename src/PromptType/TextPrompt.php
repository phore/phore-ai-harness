<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use RuntimeException;

final readonly class TextPrompt implements PromptType
{
    public ?string $alias;

    public ?string $instructions;

    public ?string $type;

    public function __construct(
        public string $text,
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'TextPrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'TextPrompt');
        $this->type = PromptMetadata::validateContentType($type, 'TextPrompt');
    }

    public static function fromFile(string $fileName): self
    {
        $content = @file_get_contents($fileName);
        if ($content === false) {
            throw new RuntimeException('Could not read prompt text file: ' . $fileName);
        }

        return new self($content);
    }

    public function type(): string
    {
        return 'text';
    }

    /**
     * @return array{type: string, text: string, alias?: string, instructions?: string, contentFormat?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'text' => $this->text,
        ];

        PromptMetadata::addToArray($array, $this->alias, $this->instructions, $this->type);

        return $array;
    }

}
