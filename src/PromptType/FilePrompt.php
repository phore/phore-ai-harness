<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use Phore\AiHarness\Helper\DataUrl;
use RuntimeException;

final readonly class FilePrompt implements PromptType
{
    public ?string $alias;

    public ?string $instructions;

    public ?string $type;

    /**
     * Creates a file prompt from already loaded file content.
     *
     * Use FilePrompt when a local file should be attached to an AI request as source material.
     * The optional metadata is rendered as a text section directly before the file segment so
     * other prompts can reference the file by alias and the model receives file-specific handling notes.
     *
     * @param string $fileName File name/path shown to the model and sent as OpenAI input_file filename.
     * @param string $content Raw file content to attach to the request.
     * @param string $contentType MIME type used in the generated data URL; defaults to application/octet-stream.
     * @param string|null $alias Optional non-empty reference name, e.g. "contract". Other prompts may refer to this alias.
     * @param string|null $instructions Optional non-empty file-specific usage instructions for the model.
     * @param string|null $type Optional non-empty logical content format, e.g. "markdown" or "csv".
     */
    public function __construct(
        public string $fileName,
        public string $content,
        public string $contentType = 'application/octet-stream',
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
    ) {
        $this->alias = PromptMetadata::validateAlias($alias, 'FilePrompt');
        $this->instructions = PromptMetadata::validateInstructions($instructions, 'FilePrompt');
        $this->type = PromptMetadata::validateContentType($type, 'FilePrompt');
    }

    /**
     * Reads a local file and creates a FilePrompt with automatically detected MIME type.
     *
     * Use this helper instead of manually calling file_get_contents() when the file exists on disk.
     * The optional alias and instructions are forwarded to the constructor and therefore included
     * in the prompt metadata.
     *
     * @param string $fileName Local file path to read and attach. The same value is exposed as prompt fileName.
     * @param string|null $alias Optional non-empty reference name for this file prompt.
     * @param string|null $instructions Optional non-empty instructions that describe how the model should use this file.
     * @param string|null $type Optional non-empty logical content format, e.g. "markdown" or "csv".
     *
     * @throws RuntimeException If the file cannot be read.
     */
    public static function fromFile(
        string $fileName,
        ?string $alias = null,
        ?string $instructions = null,
        ?string $type = null,
    ): self {
        $content = @file_get_contents($fileName);
        if ($content === false) {
            throw new RuntimeException('Could not read prompt file: ' . $fileName);
        }

        return new self(
            $fileName,
            $content,
            DataUrl::detectContentType($fileName) ?? 'application/octet-stream',
            $alias,
            $instructions,
            $type,
        );
    }

    public function type(): string
    {
        return 'file';
    }

    /**
     * @return array{type: string, fileName: string, content: string, contentType: string, alias?: string, instructions?: string, contentFormat?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'fileName' => $this->fileName,
            'content' => $this->content,
            'contentType' => $this->contentType,
        ];

        PromptMetadata::addToArray($array, $this->alias, $this->instructions, $this->type);

        return $array;
    }

}
