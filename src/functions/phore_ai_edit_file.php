<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\DataUrl;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Edit one or more local files through an AI tool call and return the final response.
 * Each target filename and its current content are attached to the prompt; unreadable
 * files are represented by empty content. The write_files callback accepts only
 * supplied target paths or their targetFileN aliases and replaces complete contents. At least one write is required.
 * Multiple writes are not transactional: an error can leave earlier files changed.
 * An optional output class controls the final response, not the file contents.
 *
 * Options (all optional):
 * - client: OpenAiClient instance, an "openai:<apikey>" DSN, or null (default)
 *   to resolve credentials through the Keystore/default client.
 * - model: Model name; default "gpt-5-mini".
 * - reasoning: Responses API settings; default ['effort' => 'low']; null omits it.
 * - timeout: Total request timeout in seconds, at least 1; default 600.
 * - connect_timeout: Connection timeout in seconds, at least 1; default 10.
 *   Both timeout options apply only when constructing a client; a supplied
 *   OpenAiClient instance keeps its own timeout settings.
 * - debug_log: false (default) disables logging; true writes ConsoleLogger output
 *   to STDERR; a LoggerInterface instance receives events and statistics.
 *   Debug mode streams text/structured responses and enables bounded retries
 *   for explicitly recoverable tool errors; image generation remains non-streaming.
 *
 * Example (after requiring vendor/autoload.php and configuring credentials):
 * <code>
 * $summary = phore_ai_edit_file(
 *     'Correct spelling without changing the meaning.',
 *     __DIR__ . '/draft.txt',
 *     options: ['model' => 'gpt-5-mini', 'debug_log' => true],
 * );
 * echo $summary;
 * </code>
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts Prompt text, prompt/tool instance, or ordered collection; strings become TextPrompt instances.
 * @param string|list<string> $filenames One target path or a non-empty list of target paths; missing files may be created.
 * @param class-string<T>|null $className Optional final-response class; null (default) returns plain text.
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     reasoning?: array<string, mixed>|null,
 *     timeout?: positive-int,
 *     connect_timeout?: positive-int,
 *     debug_log?: bool|LoggerInterface
 * } $options Optional settings; omitted keys use the defaults described above.
 * @return ($className is class-string<T> ? T : string) Hydrated final response or plain text after at least one file write.
 * @throws InvalidArgumentException For invalid targets, class, options or callback arguments.
 * @throws \Phore\AiHarness\Client\AiRequestException If the provider request fails.
 * @throws RuntimeException If a write fails or the model does not invoke the write callback.
 * @throws JsonException If a structured response cannot be decoded.
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
    $targetAliases = [];
    foreach ($filenames as $index => $targetFilename) {
        if (!is_string($targetFilename) || trim($targetFilename) === '') {
            throw new InvalidArgumentException('Every target filename must be a non-empty string.');
        }

        $targetFile = realpath($targetFilename) ?: $targetFilename;
        $targetFiles[$targetFile] = $targetFile;
        $targetAliases['targetFile' . ($index + 1)] = $targetFile;
    }
    $filesWereWritten = false;

    $writeFilesTool = new CallbackTool(
        /**
         * @param list<string> $filenames Supplied filenames or targetFileN aliases to write.
         * @param list<string> $contents Complete resulting contents in matching order.
         */
        static function (array $filenames, array $contents) use ($targetFiles, $targetAliases, &$filesWereWritten): string {
            if ($filenames === []) {
                throw new InvalidArgumentException('At least one file must be written.');
            }
            if (count($filenames) !== count($contents)) {
                throw new InvalidArgumentException('Filenames and contents must contain the same number of items.');
            }

            $contentsByFilename = [];
            foreach ($filenames as $index => $targetFilename) {
                $content = $contents[$index] ?? null;
                if (!is_string($targetFilename) || !is_string($content)) {
                    throw new InvalidArgumentException('Every filename and content must be a string.');
                }
                $targetFilename = $targetAliases[$targetFilename] ?? $targetFilename;
                if (!isset($targetFiles[$targetFilename])) {
                    throw new InvalidArgumentException('Cannot write file outside the supplied targets: ' . $targetFilename);
                }
                if (isset($contentsByFilename[$targetFilename])) {
                    throw new InvalidArgumentException('Cannot write the same target file twice: ' . $targetFilename);
                }

                $contentsByFilename[$targetFilename] = $content;
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
        'Replaces one or more supplied target files in one call. Pass each target alias (targetFile1, targetFile2, ...) and its complete resulting content at matching list positions.',
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
        'Edit only the supplied target files. Save all changes in one write_files call using their targetFileN aliases and complete resulting content.'
    );
    $items[] = $writeFilesTool;

    $ai = Toolkit::createAi($options)->with(...$items);
    $result = $className === null ? $ai->run() : $ai->runCasted($className);

    if (!$filesWereWritten) {
        throw new RuntimeException('AI response did not write a target file through write_files.');
    }

    return $result;
}
