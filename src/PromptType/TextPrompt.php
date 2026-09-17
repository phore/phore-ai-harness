<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use RuntimeException;

/**
 * Text content supplied to the model.
 *
 * TextPrompt content is treated as external/untrusted data by default. Set
 * allowInstructions to true only when instructions contained inside the text
 * are intentionally allowed to influence model behavior. The separate
 * instructions metadata is application-provided guidance about how to use the
 * source and remains instruction text regardless of allowInstructions.
 */
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
        public bool $allowInstructions = false,
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'TextPrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'TextPrompt');
        $this->type = PromptMetadata::validateContentType($type, 'TextPrompt');
    }

    /**
     * Reads text from a local file. File content is untrusted by default; pass
     * allowInstructions: true only for intentionally executable prompt text.
     */
    public static function fromFile(
        string $fileName,
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
        bool $allowInstructions = false,
    ): self {
        $content = @file_get_contents($fileName);
        if ($content === false) {
            throw new RuntimeException('Could not read prompt text file: ' . $fileName);
        }

        return new self($content, $alias, $instructions, $type, $allowInstructions);
    }

    public function type(): string
    {
        return 'text';
    }

    /**
     * @return array{type: string, text: string, allowInstructions: bool, alias?: string, instructions?: string, contentFormat?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'text' => $this->text,
            'allowInstructions' => $this->allowInstructions,
        ];

        PromptMetadata::addToArray($array, $this->alias, $this->instructions, $this->type);

        return $array;
    }

}
