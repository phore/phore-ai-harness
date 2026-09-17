<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;

/**
 * Image content supplied to the model as source material.
 *
 * Images are treated as external/untrusted data by default. Set
 * allowInstructions to true only when textual instructions visible or encoded
 * inside the image are intentionally allowed to influence model behavior. The
 * separate instructions metadata remains application-provided guidance.
 */
final readonly class ImagePrompt implements PromptType
{
    public ?string $alias;

    public ?string $instructions;

    public ?string $type;

    public function __construct(
        public string $imageUrl,
        public ?string $fileName = null,
        public ?string $mimeType = null,
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
        public bool $allowInstructions = false,
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'ImagePrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'ImagePrompt');
        $this->type = PromptMetadata::validateContentType($type, 'ImagePrompt');
    }

    /**
     * Loads a local image. Embedded image instructions are untrusted by default.
     */
    public static function fromFile(
        string $fileName,
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
        bool $allowInstructions = false,
    ): self {
        $dataUrl = DataUrl::fromFile($fileName);

        return new self(
            $dataUrl->toString(),
            $fileName,
            $dataUrl->contentType,
            $alias,
            $instructions,
            $type,
            $allowInstructions,
        );
    }

    public function type(): string
    {
        return 'image';
    }

    /**
     * @return array{type: string, imageUrl: string, allowInstructions: bool, fileName?: string, mimeType?: string, alias?: string, instructions?: string, contentFormat?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'imageUrl' => $this->imageUrl,
            'allowInstructions' => $this->allowInstructions,
        ];

        if ($this->fileName !== null) {
            $array['fileName'] = $this->fileName;
        }
        if ($this->mimeType !== null) {
            $array['mimeType'] = $this->mimeType;
        }

        PromptMetadata::addToArray($array, $this->alias, $this->instructions, $this->type);

        return $array;
    }
}
