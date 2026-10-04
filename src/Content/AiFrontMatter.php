<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\Schema\Schema\ClassSchema;
use Phore\Schema\Validator\Validator;

final readonly class AiFrontMatter extends AiMarkdown
{
    public array $header;
    public AiMarkdown $body;

    public function __construct(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        public ?ClassSchema $headerSchema = null,
    ) {
        [$header, $body] = self::parseFrontMatter($rawData);
        if ($headerSchema !== null) {
            (new Validator())->assertValid($headerSchema, $header);
        }

        $this->header = $header;
        $this->body = AiMarkdown::fromRaw($body, $fileName, $description);
        parent::__construct($rawData, $fileName ?? 'content.md', $description, $context);
    }

    public function headerEdit(string $instruction): self
    {
        $prompt = $instruction . "\nReturn only the complete header as JSON.";
        if ($this->headerSchema !== null) {
            $schema = json_encode($this->headerSchema->toJsonSchema()->toArray(), JSON_THROW_ON_ERROR);
            $prompt .= "\nThe header must match this JSON Schema:\n" . $schema;
        }

        $json = $this->ai_text($prompt, input: json_encode($this->header, JSON_THROW_ON_ERROR));
        $header = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($header) || array_is_list($header)) {
            throw new InvalidArgumentException('AI front-matter header must be a JSON object.');
        }
        if ($this->headerSchema !== null) {
            (new Validator())->assertValid($this->headerSchema, $header);
        }

        return new self(
            self::serializeFrontMatter($header, $this->body->rawData),
            $this->fileName,
            $this->description,
            headerSchema: $this->headerSchema,
        );
    }

    public function bodyEdit(string $instruction): self
    {
        $body = $this->ai_text($instruction, input: $this->body->rawData);

        return new self(
            self::serializeFrontMatter($this->header, $body),
            $this->fileName,
            $this->description,
            headerSchema: $this->headerSchema,
        );
    }

    public function edit(string $instruction): self
    {
        $edited = $this->ai_text(
            $instruction . "\nKeep valid YAML front matter and return the complete document.",
            input: $this->rawData,
        );

        return new self(
            $edited,
            $this->fileName,
            $this->description,
            headerSchema: $this->headerSchema,
        );
    }

    protected function recreate(string $rawData, ?AiContext $context = null): static
    {
        return new self(
            $rawData,
            $this->fileName,
            $this->description,
            $context,
            $this->headerSchema,
        );
    }

    private static function parseFrontMatter(string $rawData): array
    {
        if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $rawData, $matches) !== 1) {
            throw new InvalidArgumentException('AiFrontMatter requires YAML front matter delimited by ---.');
        }

        $header = yaml_parse($matches[1]);
        if (!is_array($header) || array_is_list($header)) {
            throw new InvalidArgumentException('AI front-matter header must be a YAML object.');
        }

        return [$header, $matches[2]];
    }

    private static function serializeFrontMatter(array $header, string $body): string
    {
        $yaml = yaml_emit($header, YAML_UTF8_ENCODING, YAML_LN_BREAK);
        $yaml = preg_replace('/^---\R/', '', $yaml) ?? $yaml;
        $yaml = preg_replace('/\R\.\.\.\s*$/', '', $yaml) ?? $yaml;

        return "---\n" . rtrim($yaml) . "\n---\n" . ltrim($body, "\r\n");
    }
}
