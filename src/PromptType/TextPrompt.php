<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use RuntimeException;

final readonly class TextPrompt implements PromptType
{
    public function __construct(
        public string $text,
    ) {
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
     * @return array{type: string, text: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'text' => $this->text,
        ];
    }

}
