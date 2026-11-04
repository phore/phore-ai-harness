<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use RuntimeException;

/**
 * Attach every file in a directory as typed source material.
 *
 * Supported documents, images and audio become their native input prompts;
 * unknown binary assets are named but not uploaded. Relative source filenames
 * and the trusted description are passed to the model. Embedded source
 * instructions remain untrusted by default.
 */
final readonly class PromptDirectory implements PromptType
{
    public string $directory;

    /** @var list<PromptType> */
    private array $segments;

    /**
     * Attach a directory to an AiContext or a single AI call.
     *
     * @param string $directory Local directory to read.
     * @param string|null $description Optional trusted description added to each source.
     * @param bool $recursive Whether to include subdirectories; default is true.
     * @param bool $allowInstructions Explicitly trust embedded instructions in files.
     * @throws \Phore\FileSystem\Exception\FilesystemException If directory or files cannot be read.
     * @throws RuntimeException If the directory has no files.
     * @see SourcePrompt::fromRaw()
     *
     * @example $prompts = [new PromptDirectory('/srv/feedback', 'Customer documents')];
     */
    public function __construct(
        string $directory,
        public ?string $description = null,
        public bool $recursive = true,
        public bool $allowInstructions = false,
    ) {
        $path = (string) phore_uri($directory)->abs();
        $root = phore_dir($path, ['rootDir' => $path])
            ->assertDirectory()
            ->assertReadable();
        $this->directory = $path;

        // Keep file ordering stable for prompts and structured references.
        $sources = [];
        foreach ($root->listFiles(recursive: $this->recursive, sort: 'path') as $file) {
            $file = $file->assertFile()->assertReadable();
            $relative = str_replace('\\', '/', (string) $file->getRelPath($root));
            $sources[$relative] = $file;
        }
        ksort($sources);
        if ($sources === []) {
            throw new RuntimeException('Prompt directory contains no files: ' . $path);
        }

        $segments = [];
        foreach ($sources as $relative => $file) {
            $segments[] = SourcePrompt::fromRaw(
                $relative,
                $file->get_contents(),
                instructions: $this->description,
                allowInstructions: $this->allowInstructions,
            );
        }
        $this->segments = $segments;
    }

    public function type(): string
    {
        return 'prompt_directory';
    }

    /**
     * Access the exact typed input prompts in sorted relative-path order.
     *
     * @return list<PromptType> Expanded file sources.
     * @see SourcePrompt
     *
     * @example $filePrompts = (new PromptDirectory('/srv/feedback'))->segments();
     */
    public function segments(): array
    {
        return $this->segments;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'directory' => $this->directory,
            'description' => $this->description,
            'recursive' => $this->recursive,
            'allowInstructions' => $this->allowInstructions,
            'segments' => array_map(static fn(PromptType $prompt): array => $prompt->toArray(), $this->segments),
        ];
    }
}
