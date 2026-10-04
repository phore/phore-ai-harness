<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\Helper\DataUrl;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\PromptType;
use RuntimeException;

final readonly class AiImage extends AiDocument
{
    public int $width;
    public int $height;

    public function __construct(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ) {
        $info = @getimagesizefromstring($rawData);
        if ($info === false || !isset($info[0], $info[1], $info['mime'])) {
            throw new InvalidArgumentException('Invalid or unsupported image data.');
        }

        $this->width = (int) $info[0];
        $this->height = (int) $info[1];
        parent::__construct($rawData, $fileName, ContentType::fromMimeType((string) $info['mime']), $description, $context);
    }

    public static function fromRaw(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): self {
        return new self($rawData, $fileName, $description, $context);
    }

    public static function fromFile(
        string $path,
        ?string $description = null,
        ?AiContext $context = null,
    ): self {
        [$data, $fileName] = self::readFile($path);

        return new self($data, $fileName, $description, $context);
    }

    public static function fromStream(
        mixed $stream,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): self {
        return new self(self::readStream($stream), $fileName, $description, $context);
    }

    public function toPromptType(): PromptType
    {
        return new ImagePrompt(
            (new DataUrl($this->rawData, $this->contentType))->toString(),
            $this->fileName,
            $this->contentType,
            instructions: $this->description,
            allowInstructions: false,
        );
    }

    /**
     * Resize the image proportionally and preserve its AI context.
     *
     * @param int $maxWidth Maximum output width in pixels.
     * @param int $maxHeight Maximum output height in pixels.
     * @return self Original object when no resize is needed, otherwise resized content.
     * @throws RuntimeException When GD is unavailable or encoding fails.
     * @example $small = $image->resizedToFit(1600, 1600);
     * @see withContext()
     */
    public function resizedToFit(int $maxWidth, int $maxHeight): self
    {
        if ($maxWidth < 1 || $maxHeight < 1) {
            throw new InvalidArgumentException('Image resize bounds must be positive integers.');
        }
        if ($this->width <= $maxWidth && $this->height <= $maxHeight) {
            return $this;
        }
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Image resizing requires ext-gd.');
        }

        $scale = min($maxWidth / $this->width, $maxHeight / $this->height);
        $width = max(1, (int) floor($this->width * $scale));
        $height = max(1, (int) floor($this->height * $scale));
        $source = @imagecreatefromstring($this->rawData);
        if ($source === false) {
            throw new RuntimeException('Could not decode image for resizing.');
        }

        $target = imagecreatetruecolor($width, $height);
        if ($target === false) {
            imagedestroy($source);
            throw new RuntimeException('Could not allocate resized image.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $this->width, $this->height);

        ob_start();
        $ok = match ($this->contentType) {
            'image/jpeg' => imagejpeg($target, null, 90),
            'image/webp' => imagewebp($target, null, 90),
            'image/gif' => imagegif($target),
            default => imagepng($target),
        };
        $data = ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);

        if (!$ok || !is_string($data)) {
            throw new RuntimeException('Could not encode resized image.');
        }

        return new self($data, $this->fileName, $this->description, $this->ai_get_context());
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'contentType' => $this->contentType,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    protected function recreate(string $rawData, ?AiContext $context = null): static
    {
        return new self($rawData, $this->fileName, $this->description, $context);
    }
}
