<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;

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
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'AudioPrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'AudioPrompt');
        $this->type = PromptMetadata::validateContentType($type, 'AudioPrompt');
    }

    public static function fromFile(string $fileName): self
    {
        $data = @file_get_contents($fileName);
        if ($data === false) {
            throw new \RuntimeException('Could not read prompt audio file: ' . $fileName);
        }

        return new self(base64_encode($data), self::detectFormat($fileName), $fileName);
    }

    public function type(): string
    {
        return 'audio';
    }

    /**
     * @return array{type: string, data: string, format: string, fileName?: string, alias?: string, instructions?: string, contentFormat?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'data' => $this->data,
            'format' => $this->format,
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
