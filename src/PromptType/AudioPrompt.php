<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;

/**
 * Audio content supplied to the model as source material.
 *
 * Audio is treated as external/untrusted data by default. Set
 * allowInstructions to true only when spoken or encoded instructions inside
 * the audio are intentionally allowed to influence model behavior. The
 * separate instructions metadata remains application-provided guidance.
 */
final readonly class AudioPrompt implements PromptType
{
    public ?string $alias;

    public ?string $instructions;

    public ?string $type;

    public function __construct(
        public string $data,
        public string $format,
        public ?string $fileName = null,
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
        public bool $allowInstructions = false,
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'AudioPrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'AudioPrompt');
        $this->type = PromptMetadata::validateContentType($type, 'AudioPrompt');
    }

    /**
     * Loads a local audio file. Embedded audio instructions are untrusted by default.
     */
    public static function fromFile(
        string $fileName,
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
        bool $allowInstructions = false,
    ): self {
        $data = @file_get_contents($fileName);
        if ($data === false) {
            throw new \RuntimeException('Could not read prompt audio file: ' . $fileName);
        }

        return new self(
            base64_encode($data),
            self::detectFormat($fileName),
            $fileName,
            $alias,
            $instructions,
            $type,
            $allowInstructions,
        );
    }

    public function type(): string
    {
        return 'audio';
    }

    /**
     * @return array{type: string, data: string, format: string, allowInstructions: bool, fileName?: string, alias?: string, instructions?: string, contentFormat?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'data' => $this->data,
            'format' => $this->format,
            'allowInstructions' => $this->allowInstructions,
        ];

        if ($this->fileName !== null) {
            $array['fileName'] = $this->fileName;
        }

        PromptMetadata::addToArray($array, $this->alias, $this->instructions, $this->type);

        return $array;
    }

    private static function detectFormat(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $extension;
        }

        return match (DataUrl::detectContentType($fileName)) {
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/wave', 'audio/x-wav' => 'wav',
            default => 'mp3',
        };
    }
}
