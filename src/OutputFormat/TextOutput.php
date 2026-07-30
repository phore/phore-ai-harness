<?php

declare(strict_types=1);

namespace Phore\AiHarness\OutputFormat;

final readonly class TextOutput implements OutputFormat
{
    public function __construct(
        public ?string $description = null,
    ) {
    }

    public function type(): string
    {
        return 'text';
    }

    /**
     * @return array{type: string, description?: string}
     */
    public function toArray(): array
    {
        $array = ['type' => $this->type()];

        if ($this->description !== null) {
            $array['description'] = $this->description;
        }

        return $array;
    }
}
