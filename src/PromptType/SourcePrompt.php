<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use InvalidArgumentException;
use Phore\AiHarness\Content\ContentType;
use Phore\AiHarness\Helper\DataUrl;

/**
 * Selects the appropriate input prompt for a local source.
 *
 * Supported images and audio use their own prompt types; supported document
 * formats use FilePrompt. Other valid UTF-8 files are attached as plain text.
 * Unsupported binary assets are represented by filename only, never silently
 * discarded. Source content is untrusted unless explicitly opted in.
 */
final class SourcePrompt
{
    /**
     * Load a file and create a suitable source prompt.
     *
     * @param string $fileName Readable local file.
     * @param string|null $alias Optional source reference.
     * @param string|null $instructions Trusted description of the source.
     * @param bool $allowInstructions Whether instructions *inside* the source may be followed.
     * @param string|null $displayName Name exposed to the model instead of the local path.
     * @return PromptType FilePrompt, ImagePrompt, AudioPrompt or a filename-only TextPrompt.
     * @throws \Phore\FileSystem\Exception\FilesystemException If the source is unreadable.
     * @see fromRaw()
     *
     * @example $prompt = SourcePrompt::fromFile('/project/feedback.pdf', instructions: 'Client feedback.');
     */
    public static function fromFile(
        string $fileName,
        ?string $alias = null,
        ?string $instructions = null,
        bool $allowInstructions = false,
        ?string $displayName = null,
    ): PromptType {
        $file = phore_file($fileName)->assertFile()->assertReadable();

        return self::fromRaw(
            $displayName ?? $fileName,
            $file->get_contents(),
            $alias,
            $instructions,
            $allowInstructions,
        );
    }

    /**
     * Choose a prompt for data already read through an application filesystem.
     *
     * The caller's filename is retained for documentation and references;
     * extension-based format selection follows ContentType's maintained map.
     *
     * @param string $fileName Source filename shown to the model.
     * @param string $data Source bytes.
     * @param string|null $alias Optional reference name.
     * @param string|null $instructions Trusted description.
     * @param bool $allowInstructions Opt in to embedded source instructions.
     * @return PromptType Classified source prompt.
     * @throws InvalidArgumentException For an empty source filename.
     * @see ContentType::fromFileName()
     *
     * @example $prompt = SourcePrompt::fromRaw('briefing.pdf', $bytes, instructions: 'Briefing');
     */
    public static function fromRaw(
        string $fileName,
        string $data,
        ?string $alias = null,
        ?string $instructions = null,
        bool $allowInstructions = false,
    ): PromptType {
        if (trim($fileName) === '') {
            throw new InvalidArgumentException('Source filename must not be empty.');
        }

        // Only ContentType's OpenAI-compatible extensions are classified.
        try {
            $mime = ContentType::fromFileName($fileName)->mimeType();
        } catch (InvalidArgumentException) {
            $mime = null;
        }

        if ($mime !== null && str_starts_with($mime, 'image/')) {
            return new ImagePrompt(
                (new DataUrl($data, $mime))->toString(),
                $fileName,
                $mime,
                alias: $alias,
                instructions: $instructions,
                allowInstructions: $allowInstructions,
            );
        }
        if ($mime !== null && str_starts_with($mime, 'audio/')) {
            return new AudioPrompt(
                base64_encode($data),
                $mime === 'audio/wav' ? 'wav' : 'mp3',
                $fileName,
                alias: $alias,
                instructions: $instructions,
                allowInstructions: $allowInstructions,
            );
        }
        if ($mime !== null && in_array($mime, ContentType::fileMimeTypes(), true)) {
            return new FilePrompt(
                $fileName,
                $data,
                $mime,
                alias: $alias,
                instructions: $instructions,
                allowInstructions: $allowInstructions,
            );
        }

        if (!str_contains($data, "\0")
            && preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $data) !== 1
            && preg_match('//u', $data) === 1) {
            return new FilePrompt(
                $fileName,
                $data,
                'text/plain',
                alias: $alias,
                instructions: $instructions,
                allowInstructions: $allowInstructions,
            );
        }

        // Unknown binary inputs are represented only by name, not raw bytes.
        return new TextPrompt(
            'Binary source (filename only, no content supplied): ' . $fileName,
            alias: $alias,
            instructions: $instructions,
            allowInstructions: false,
        );
    }
}
