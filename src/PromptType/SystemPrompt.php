<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use RuntimeException;

/**
 * Explicit system-level instructions for the model.
 *
 * Unlike content PromptTypes, SystemPrompt is always an instruction source and
 * therefore has no allowInstructions flag. Its text is sent through the provider
 * instructions channel and is never treated as external/untrusted source data.
 */
final readonly class SystemPrompt implements PromptType
{
    public function __construct(
        public string $text,
    ) {
    }

    /**
     * Loads trusted system instructions from a local file.
     *
     * Use this only for files controlled as prompt instructions. External or
     * user-controlled files belong in TextPrompt/FilePrompt with the safer
     * allowInstructions=false default.
     */
    public static function fromFile(string $fileName): self
    {
        $content = @file_get_contents($fileName);
        if ($content === false) {
            throw new RuntimeException('Could not read prompt system file: ' . $fileName);
        }

        return new self($content);
    }

    public function type(): string
    {
        return 'system';
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
