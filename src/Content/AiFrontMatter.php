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
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        [$header, $body] = self::parseFrontMatter($rawData);
        if ($headerSchema !== null) {
            (new Validator())->assertValid($headerSchema, $header);
        }

        $this->header = $header;
        $this->body = AiMarkdown::fromRaw($body, $fileName, $description);
        parent::__construct(
            $rawData,
            $fileName ?? 'content.md',
            $description,
            $context,
            $id,
            $aliases,
            $instructions,
        );
    }

    /**
     * Create a front-matter document from raw Markdown.
     *
     * @return static
     * @example $page = AiFrontMatter::fromRaw($markdown, headerSchema: $schema);
     * @see AiDocumentFactory::fromRaw()
     */
    public static function fromRaw(
        string $rawData,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?ClassSchema $headerSchema = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): static {
        return new static(
            $rawData,
            $fileName,
            $description,
            $context,
            $headerSchema,
            $id,
            $aliases,
            $instructions,
        );
    }

    /**
     * Load a front-matter document from a readable Markdown file.
     *
     * @return static
     * @throws \RuntimeException When the file cannot be read.
     * @example $page = AiFrontMatter::fromFile('/tmp/page.md', headerSchema: $schema);
     * @see fromRaw()
     */
    public static function fromFile(
        string $path,
        ?string $description = null,
        ?AiContext $context = null,
        ?ClassSchema $headerSchema = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): static {
        [$data, $fileName] = self::readFile($path);

        return new static(
            $data,
            $fileName,
            $description,
            $context,
            $headerSchema,
            $id,
            $aliases,
            $instructions,
        );
    }

    /**
     * Create a front-matter document from a readable stream.
     *
     * @return static
     * @example $page = AiFrontMatter::fromStream($stream, fileName: 'page.md');
     * @see fromRaw()
     */
    public static function fromStream(
        mixed $stream,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
        ?ClassSchema $headerSchema = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ): static {
        return new static(
            self::readStream($stream),
            $fileName,
            $description,
            $context,
            $headerSchema,
            $id,
            $aliases,
            $instructions,
        );
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
            $this->ai_get_context(),
            $this->headerSchema,
            null,
            $this->aliases,
            $this->instructions,
        );
    }

    public function bodyEdit(string $instruction): self
    {
        $body = $this->ai_text($instruction, input: $this->body->rawData);

        return new self(
            self::serializeFrontMatter($this->header, $body),
            $this->fileName,
            $this->description,
            $this->ai_get_context(),
            $this->headerSchema,
            null,
            $this->aliases,
            $this->instructions,
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
            $this->ai_get_context(),
            $this->headerSchema,
            null,
            $this->aliases,
            $this->instructions,
        );
    }

    /**
     * Check whether Markdown starts with conventional YAML front matter.
     *
     * @example AiFrontMatter::hasFrontMatter($markdown);
     * @see __construct()
     */
    public static function hasFrontMatter(string $rawData): bool
    {
        return preg_match('/^---\\R.*?\\R---(?:\\R|$)/s', $rawData) === 1;
    }

    protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static {
        return new self(
            $rawData,
            $this->fileName,
            $this->description,
            $context,
            $this->headerSchema,
            $id,
            $this->aliases,
            $this->instructions,
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
