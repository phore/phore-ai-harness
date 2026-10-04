<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\AudioPrompt;
use Phore\AiHarness\PromptType\PromptType;

final readonly class AiAudio extends AiContent
{
    public string $format;

    public function __construct(string $rawData, string $format, ?string $fileName = null, ?string $description = null, ?AiContext $context = null)
    {
        $format = strtolower(trim($format));
        if ($format === '') {
            throw new InvalidArgumentException('AI audio format must not be empty.');
        }
        $this->format = $format;
        parent::__construct($rawData, $fileName, $description, $context);
    }

    public static function fromRaw(string $rawData, string $format, ?string $fileName = null, ?string $description = null, ?AiContext $context = null): self
    {
        return new self($rawData, $format, $fileName, $description, $context);
    }

    public static function fromFile(string $path, ?string $description = null, ?AiContext $context = null): self
    {
        [$data, $fileName] = self::readFile($path);
        $format = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return new self($data, $format !== '' ? $format : 'mp3', $fileName, $description, $context);
    }

    public static function fromStream(mixed $stream, string $format, ?string $fileName = null, ?string $description = null, ?AiContext $context = null): self
    {
        return new self(self::readStream($stream), $format, $fileName, $description, $context);
    }

    public function toPromptType(): PromptType
    {
        return new AudioPrompt(
            base64_encode($this->rawData),
            $this->format,
            $this->fileName,
            instructions: $this->description,
            allowInstructions: false,
        );
    }

    public function toArray(): array
    {
        return parent::toArray() + ['format' => $this->format];
    }
}
