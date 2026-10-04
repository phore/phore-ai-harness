<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;

final readonly class AiCode extends AiDocument
{
    public string $language;
    public ?string $version;

    public function __construct(
        string $rawData,
        string $language,
        ?string $version = null,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        $language = trim($language);
        if ($language === '') {
            throw new InvalidArgumentException('AI code language must not be empty.');
        }

        $this->language = $language;
        $this->version = self::normalizeOptionalText($version, 'code version');
        parent::__construct(
            $rawData,
            $fileName,
            'text/plain',
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    public static function fromRaw(
        string $rawData,
        string $language,
        ?string $version = null,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        return new self(
            $rawData,
            $language,
            $version,
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
        string $language,
        ?string $version = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        [$data, $fileName] = self::readFile($path);

        return new self(
            $data,
            $language,
            $version,
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
        string $language,
        ?string $version = null,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): self {
        return new self(
            self::readStream($stream),
            $language,
            $version,
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
        $meta = 'Programming language: ' . $this->language;
        if ($this->version !== null) {
            $meta .= '; version: ' . $this->version;
        }
        if ($this->description !== null) {
            $meta .= '. ' . $this->description;
        }

        return new FilePrompt(
            $this->fileName ?? ('code.' . strtolower($this->language)),
            $this->rawData,
            'text/plain',
            alias: $this->id,
            instructions: trim($meta . "\n" . ($this->promptInstructions() ?? '')),
            type: $this->language,
            allowInstructions: false,
        );
    }

    public function toArray(): array
    {
        return parent::toArray() + ['language' => $this->language, 'version' => $this->version];
    }

    protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static {
        return new self(
            $rawData,
            $this->language,
            $this->version,
            $this->fileName,
            $this->description,
            $context,
            $id,
            $this->aliases,
            $this->instructions,
        );
    }
}
