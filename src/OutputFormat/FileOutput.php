<?php

declare(strict_types=1);

namespace Phore\AiHarness\OutputFormat;

final readonly class FileOutput implements OutputFormat
{
    public function __construct(
        public ?string $fileName = null,
        public ?string $mimeType = null,
        public ?string $description = null,
    ) {
    }

    public function type(): string
    {
        return 'file';
    }

    /**
     * @return array{type: string, fileName?: string, mimeType?: string, description?: string}
     */
    public function toArray(): array
    {
        $array = ['type' => $this->type()];

        if ($this->fileName !== null) {
            $array['fileName'] = $this->fileName;
        }
        if ($this->mimeType !== null) {
            $array['mimeType'] = $this->mimeType;
        }
        if ($this->description !== null) {
            $array['description'] = $this->description;
        }

        return $array;
    }
}
