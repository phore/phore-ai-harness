<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\DataUrl;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\Result\ImageResultType;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ImageGenerationTool;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Runs a text prompt via the high-level Phore AI facade and returns the plain output text.
 *
 * Strings are converted to `TextPrompt` instances. Arrays may contain strings,
 * `PromptType` instances and `ToolType` instances.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
 */
function phore_ai_text(string|PromptType|ToolType|array $prompts, array $options = []): string
{
    return Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->run();
}

/**
 * Runs an image generation prompt and returns generated image data.
 *
 * Strings are converted to `TextPrompt` instances. Arrays may contain strings,
 * `PromptType` instances and `ToolType` instances. If no `ImageGenerationTool`
 * is provided in the prompt list, one is created from image-related options.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 * - `size`: `auto`, `1024x1024`, `1536x1024` or `1024x1536`
 * - `output_format`: `png`, `jpeg` or `webp`
 * - `quality`: `auto`, `low`, `medium` or `high`
 * - `background`: `auto`, `transparent` or `opaque`
 *
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     timeout?: int,
 *     connect_timeout?: int,
 *     size?: 'auto'|'1024x1024'|'1536x1024'|'1024x1536',
 *     output_format?: 'png'|'jpeg'|'webp',
 *     quality?: 'auto'|'low'|'medium'|'high',
 *     background?: 'auto'|'transparent'|'opaque'
 * } $options
 */
function phore_ai_image(string|PromptType|ToolType|array $prompts, array $options = []): ImageResultType
{
    $items = Toolkit::normalizePromptItems($prompts);
    if (!Toolkit::hasTool($items, ImageGenerationTool::class)) {
        $items[] = new ImageGenerationTool(
            size: $options['size'] ?? null,
            output_format: $options['output_format'] ?? null,
            quality: $options['quality'] ?? null,
            background: $options['background'] ?? null,
        );
    }

    return Toolkit::createAi($options)
        ->with(...$items)
        ->runImage(Toolkit::contentTypeFromImageOutputFormat($options['output_format'] ?? 'png'));
}

/**
 * Runs a prompt with OpenAI structured output and hydrates the response into a PHP object.
 *
 * Strings are converted to `TextPrompt` instances. Arrays may contain strings,
 * `PromptType` instances and `ToolType` instances.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param class-string<T> $className
 * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
 * @return T
 */
function phore_ai_struct(string|PromptType|ToolType|array $prompts, string $className, array $options = []): object
{
    /** @var T $result */
    $result = Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->runCasted($className);

    return $result;
}


/**
 * Runs a prompt with OpenAI structured output and hydrates the response into a list of PHP objects.
 *
 * The model is instructed via structured output to return an object with an `items` array.
 * Each entry in `items` is hydrated into `$className`.
 *
 * Strings are converted to `TextPrompt` instances. Arrays may contain strings,
 * `PromptType` instances and `ToolType` instances.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param class-string<T> $className
 * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
 * @return list<T>
 */
function phore_ai_struct_array(string|PromptType|ToolType|array $prompts, string $className, array $options = []): array
{
    /** @var list<T> $result */
    $result = Toolkit::createAi($options)
        ->with(...Toolkit::normalizePromptItems($prompts))
        ->runCastedArray($className);

    return $result;
}

/**
 * Runs a file-editing prompt against one or more local files.
 *
 * Each target filename and its current content are attached to the prompt. If a
 * file cannot be read, empty content is attached. The AI receives one callback
 * tool that can replace multiple complete file contents in a single call.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param string|list<string> $filenames One filename or a list of filenames to edit.
 * @param class-string<T>|null $className
 * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
 * @return ($className is class-string<T> ? T : string)
 */
function phore_ai_edit_file(string|PromptType|ToolType|array $prompts, string|array $filenames, ?string $className = null, array $options = []): object|string
{
    if ($className !== null && !class_exists($className)) {
        throw new InvalidArgumentException('Output class does not exist: ' . $className);
    }

    $filenames = is_string($filenames) ? [$filenames] : array_values($filenames);
    if ($filenames === []) {
        throw new InvalidArgumentException('At least one target filename is required.');
    }

    $targetFiles = [];
    foreach ($filenames as $targetFilename) {
        if (!is_string($targetFilename) || trim($targetFilename) === '') {
            throw new InvalidArgumentException('Every target filename must be a non-empty string.');
        }

        $targetFile = realpath($targetFilename) ?: $targetFilename;
        $targetFiles[$targetFile] = $targetFile;
    }
    $filesWereWritten = false;

    $writeFilesTool = new CallbackTool(
        /**
         * @param list<array{filename: string, content: string}> $files Complete resulting content keyed by target filename.
         */
        static function (array $files) use ($targetFiles, &$filesWereWritten): string {
            if ($files === []) {
                throw new InvalidArgumentException('At least one file must be written.');
            }

            $contentsByFilename = [];
            foreach ($files as $file) {
                if (!is_array($file) || !isset($file['filename'], $file['content']) || !is_string($file['filename']) || !is_string($file['content'])) {
                    throw new InvalidArgumentException('Every file must contain string filename and content values.');
                }
                if (!isset($targetFiles[$file['filename']])) {
                    throw new InvalidArgumentException('Cannot write file outside the supplied targets: ' . $file['filename']);
                }
                if (isset($contentsByFilename[$file['filename']])) {
                    throw new InvalidArgumentException('Cannot write the same target file twice: ' . $file['filename']);
                }

                $contentsByFilename[$file['filename']] = $file['content'];
            }

            $writtenFiles = [];
            foreach ($contentsByFilename as $targetFile => $content) {
                $bytes = @file_put_contents($targetFile, $content, LOCK_EX);
                if ($bytes === false) {
                    throw new RuntimeException('Could not write target file: ' . $targetFile);
                }
                $writtenFiles[] = ['filename' => $targetFile, 'bytes' => $bytes];
            }

            $filesWereWritten = true;

            return Toolkit::jsonEncode(['files' => $writtenFiles]);
        },
        'write_files',
        'Replaces one or more supplied target files in one call. Pass the exact supplied filename and the complete resulting content for every file to write.',
    );

    $items = Toolkit::normalizePromptItems($prompts);
    foreach (array_values($targetFiles) as $index => $targetFile) {
        $originalContent = @file_get_contents($targetFile);
        $items[] = new FilePrompt(
            $targetFile,
            $originalContent === false ? '' : $originalContent,
            DataUrl::detectContentType($targetFile) ?? 'application/octet-stream',
            alias: 'targetFile' . ($index + 1),
            instructions: 'Editable target file.',
        );
    }
    $items[] = new SystemPrompt(
        'Edit only the supplied target files. Save all changes in one write_files call using exact supplied filenames and complete resulting content.'
    );
    $items[] = $writeFilesTool;

    $ai = Toolkit::createAi($options)->with(...$items);
    $result = $className === null ? $ai->run() : $ai->runCasted($className);

    if (!$filesWereWritten) {
        throw new RuntimeException('AI response did not write a target file through write_files.');
    }

    return $result;
}


/**
 * Returns the last OpenAI Responses API request created by this process.
 */
function get_last_ai_request(): ?AiRequest
{
    return AiRequest::$last;
}

/**
 * Returns the last OpenAI Responses API response created by this process.
 */
function get_last_ai_response(): ?AiResponse
{
    return AiResponse::$last;
}
