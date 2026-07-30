<?php

declare(strict_types=1);

namespace Phore\AiHarness\OutputFormat;

final readonly class ImageOutput implements OutputFormat
{
    public function __construct(
        public string $mimeType = 'image/png',
        public ?string $description = null,
    ) {
    }

    public function type(): string
    {
        return 'image';
    }

    /**
     * @return array{type: string, mimeType: string, description?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'mimeType' => $this->mimeType,
        ];

        if ($this->description !== null) {
            $array['description'] = $this->description;
        }

        return $array;
    }
}
