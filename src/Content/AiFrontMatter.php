<?php

declare(strict_types=1);

namespace Phore\AiHarness\Content;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\Schema\Schema\ClassSchema;
use Phore\Schema\Validator\Validator;

/**
 * Markdown document with structured YAML front matter.
 */
final readonly class AiFrontMatter extends AiMarkdown
{
    /**
     * @param array<string, mixed> $header Structured YAML front matter.
     */
    public function __construct(
        public array $header,
        public AiMarkdown $body,
        public ?ClassSchema $headerSchema = null,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ) {
        if ($headerSchema !== null) {
            (new Validator())->assertValid($headerSchema, $header);
        }

        parent::__construct(
            self::serialize($header, $body->rawData),
            $fileName ?? 'content.md',
            $description,
            $context,
        );
    }

    /**
     * Parse a Markdown document with YAML front matter.
     *
     * @param string $rawData Complete Markdown file including --- front-matter delimiters.
     * @param ClassSchema|null $headerSchema Optional phore/schema contract for validation and AI guidance.
     * @param string|null $fileName Optional source filename.
     * @param string|null $description Trusted application metadata.
     * @param AiContext|null $context Optional idle AI context.
     * @return self Parsed front-matter document.
     * @throws InvalidArgumentException When the front matter is missing or invalid.
     * @example AiFrontMatter::fromRaw($markdown, $schema);
     * @see headerEdit()
     */
    public static function fromRaw(
        string $rawData,
        ?ClassSchema $headerSchema = null,
        ?string $fileName = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        [$header, $body] = self::parse($rawData);

        return new self(
            $header,
            AiMarkdown::fromRaw($body, $fileName),
            $headerSchema,
            $fileName,
            $description,
            $context,
        );
    }

    /**
     * Load and parse a Markdown file with YAML front matter.
     *
     * @param string $path Readable Markdown file.
     * @param ClassSchema|null $headerSchema Optional schema for header validation.
     * @param string|null $description Trusted application metadata.
     * @param AiContext|null $context Optional idle context.
     * @return self Parsed front-matter document.
     * @throws \RuntimeException When the file is not readable.
     * @example AiFrontMatter::fromFile('/tmp/article.md', $schema);
     * @see fromRaw()
     */
    public static function fromFile(
        string $path,
        ?ClassSchema $headerSchema = null,
        ?string $description = null,
        ?AiContext $context = null,
    ): static {
        [$data, $fileName] = self::readFile($path);

        return self::fromRaw($data, $headerSchema, $fileName, $description, $context);
    }

    /**
     * Edit only the structured front-matter header.
     *
     * When a ClassSchema is present, its generated JSON Schema including property
     * descriptions is supplied to the model and the returned header is validated.
     *
     * @param string $instruction Requested header change.
     * @return self New document with the same Markdown body.
     * @example $published = $document->headerEdit('Set status to published.');
     * @see ClassSchema::toJsonSchema()
     */
    public function headerEdit(string $instruction): self
    {
        $prompt = $instruction . "\nReturn only the complete header as JSON.";
        if ($this->headerSchema !== null) {
            $schema = json_encode(
                $this->headerSchema->toJsonSchema()->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
            $prompt .= "\nThe header must match this JSON Schema:\n" . $schema;
        }

        $json = $this->ai_text(
            $prompt,
            input: json_encode($this->header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $header = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($header) || array_is_list($header)) {
            throw new InvalidArgumentException('AI front-matter header must be a JSON object.');
        }
        if ($this->headerSchema !== null) {
            (new Validator())->assertValid($this->headerSchema, $header);
        }

        return new self(
            $header,
            $this->body,
            $this->headerSchema,
            $this->fileName,
            $this->description,
        );
    }

    /**
     * Edit only the Markdown body.
     *
     * @param string $instruction Requested body transformation.
     * @return self New document retaining the structured header.
     * @example $edited = $document->bodyEdit('Shorten the introduction.');
     * @see AiText::edit()
     */
    public function bodyEdit(string $instruction): self
    {
        $body = $this->ai_text($instruction, input: $this->body->rawData);

        return new self(
            $this->header,
            AiMarkdown::fromRaw($body, $this->body->fileName, $this->body->description),
            $this->headerSchema,
            $this->fileName,
            $this->description,
        );
    }

    /**
     * Edit header and body together as one Markdown document.
     *
     * @param string $instruction Requested whole-document change.
     * @return self Parsed and schema-validated edited document.
     * @example $edited = $document->edit('Update title and rewrite the introduction.');
     * @see headerEdit()
     * @see bodyEdit()
     */
    public function edit(string $instruction): self
    {
        $edited = $this->ai_text(
            $instruction
                . "\nKeep valid YAML front matter delimited by --- and return the complete document.",
            input: $this->rawData,
        );

        return self::fromRaw($edited, $this->headerSchema, $this->fileName, $this->description);
    }

    protected function recreate(string $rawData, ?AiContext $context = null): static
    {
        return self::fromRaw($rawData, $this->headerSchema, $this->fileName, $this->description, $context);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function parse(string $rawData): array
    {
        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $rawData, $matches) !== 1) {
            throw new InvalidArgumentException('AiFrontMatter requires YAML front matter delimited by ---.');
        }

        $header = yaml_parse($matches[1]);
        if (!is_array($header) || array_is_list($header)) {
            throw new InvalidArgumentException('AI front-matter header must be a YAML object.');
        }

        return [$header, $matches[2]];
    }

    /**
     * @param array<string, mixed> $header
     */
    private static function serialize(array $header, string $body): string
    {
        $yaml = yaml_emit($header, YAML_UTF8_ENCODING, YAML_LN_BREAK);
        $yaml = preg_replace('/\A---\R/', '', $yaml) ?? $yaml;
        $yaml = preg_replace('/\R\.\.\.\s*\z/', '', $yaml) ?? $yaml;

        return "---\n" . rtrim($yaml) . "\n---\n" . ltrim($body, "\r\n");
    }
}
