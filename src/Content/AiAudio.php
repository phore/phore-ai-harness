<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\AudioPrompt;
use Phore\AiHarness\PromptType\PromptType;

final readonly class AiAudio extends AiDocument
{
    public string $format;

    public function __construct(
        string $rawData,
        string $format,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        $format = strtolower(trim($format));
        if ($format === '') {
            throw new InvalidArgumentException('AI audio format must not be empty.');
        }

        $this->format = $format;
        $contentType = match ($format) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            default => throw new InvalidArgumentException('Unsupported AI audio format: ' . $format),
        };
        parent::__construct(
            $rawData,
            $fileName,
            $contentType,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public static function fromRaw(
        string $rawData,
        string $format,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        return new self(
            $rawData,
            $format,
            $fileName,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public static function fromFile(
        string $path,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        [$data, $fileName] = self::readFile($path);
        $format = ContentType::fromFileName($fileName)->extension();

        return new self(
            $data,
            $format,
            $fileName,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public static function fromStream(
        mixed $stream,
        string $format,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        return new self(
            self::readStream($stream),
            $format,
            $fileName,
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public function toPromptType(): PromptType
    {
        return new AudioPrompt(
            base64_encode($this->rawData),
            $this->format,
            $this->fileName,
            alias: $this->id,
            instructions: $this->promptInstructions(),
            allowInstructions: false,
        );
    }

    public function toArray(): array
    {
        return parent::toArray() + ['format' => $this->format];
    }

    protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static {
        return new self(
            $rawData,
            $this->format,
            $this->fileName,
            $this->description,
            $context,
            $id,
            $this->aliases,
            $this->instructions,
        );
    }
}
