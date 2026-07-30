<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;

final readonly class ImagePrompt implements PromptType
{
    public function __construct(
        public string $imageUrl,
        public ?string $fileName = null,
        public ?string $mimeType = null,
    ) {
    }

    public static function fromFile(string $fileName): self
    {
        $dataUrl = DataUrl::fromFile($fileName);

        return new self($dataUrl->toString(), $fileName, $dataUrl->contentType);
    }

    public function type(): string
    {
        return 'image';
    }

    /**
     * @return array{type: string, imageUrl: string, fileName?: string, mimeType?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'imageUrl' => $this->imageUrl,
        ];

        if ($this->fileName !== null) {
            $array['fileName'] = $this->fileName;
        }
        if ($this->mimeType !== null) {
            $array['mimeType'] = $this->mimeType;
        }

        return $array;
    }
}
