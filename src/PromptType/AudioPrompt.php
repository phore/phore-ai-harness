<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;

final readonly class AudioPrompt implements PromptType
{
    public function __construct(
        public string $data,
        public string $format,
        public ?string $fileName = null,
    ) {
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
     * @return array{type: string, data: string, format: string, fileName?: string}
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
