<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\DefaultSystemPrompt;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Runs a file-editing prompt against exactly one existing local file.
 *
 * The target file is not sent as prompt content automatically. Instead, the AI
 * receives two callback tools scoped to this single file: one tool to read the
 * current content and one tool to replace the complete content. The write must
 * happen through the callback tool, so PHP performs the actual filesystem write.
 *
 * Options:
 * - `client`: optional `OpenAiClient`, DSN string such as `openai:<key>`, or `null` for Keystore/default client
 * - `model`: optional OpenAI model name, defaults to `gpt-5-mini`
 * - `timeout`: optional request timeout in seconds, defaults to `OpenAiClient::DEFAULT_TIMEOUT`
 * - `connect_timeout`: optional connect timeout in seconds, defaults to `OpenAiClient::DEFAULT_CONNECT_TIMEOUT`
 *
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param class-string<T>|null $className
 * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
 * @return ($className is class-string<T> ? T : string)
 */
function phore_ai_file(string|PromptType|ToolType|array $prompts, string $filename, ?string $className = null, array $options = []): object|string
{
    if (!is_file($filename)) {
        throw new InvalidArgumentException('Target file must exist: ' . $filename);
    }
    if (!is_readable($filename)) {
        throw new InvalidArgumentException('Target file must be readable: ' . $filename);
    }
    if (!is_writable($filename)) {
        throw new InvalidArgumentException('Target file must be writable: ' . $filename);
    }
    if ($className !== null && !class_exists($className)) {
        throw new InvalidArgumentException('Output class does not exist: ' . $className);
    }

    $targetFile = realpath($filename) ?: $filename;
    $fileWasWritten = false;

    $readFileTool = new CallbackTool(
        static function () use ($targetFile): string {
            $content = @file_get_contents($targetFile);
            if ($content === false) {
                throw new RuntimeException('Could not read target file: ' . $targetFile);
            }

            return $content;
        },
        'get_file_content',
        'Returns the current complete content of the one target file. The file path is fixed by PHP and cannot be changed by the model.',
    );

    $writeFileTool = new CallbackTool(
        static function (string $content, string $summary) use ($targetFile, &$fileWasWritten): string {
            if (!is_file($targetFile)) {
                throw new RuntimeException('Target file no longer exists: ' . $targetFile);
            }

            $bytes = @file_put_contents($targetFile, $content, LOCK_EX);
            if ($bytes === false) {
                throw new RuntimeException('Could not write target file: ' . $targetFile);
            }

            $fileWasWritten = true;

            return Toolkit::jsonEncode([
                'filename' => $targetFile,
                'bytes' => $bytes,
                'summary' => $summary,
            ]);
        },
        'write_file_content',
        'Replaces the complete content of the one target file. The file path is fixed by PHP and cannot be changed by the model. Parameters: content is the full new file content, summary is a concise description of the performed changes.',
    );

    $items = Toolkit::normalizePromptItems($prompts);
    $items[] = new SystemPrompt(
        DefaultSystemPrompt::TEXT . "\n\n" .
        'You are editing exactly one existing local file. ' .
        'Use get_file_content to read the current file content before deciding on changes. ' .
        'Write changes only by calling write_file_content exactly once with the complete new file content. ' .
        'Do not attempt to edit, create, delete or reference any other local file. ' .
        'After the required tool calls, return the requested result to the user. ' .
        ($className === null
            ? 'Return a concise text summary, for example a list of changes.'
            : 'Return the final answer as structured data matching the requested output schema.')
    );
    $items[] = $readFileTool;
    $items[] = $writeFileTool;

    $ai = Toolkit::createAi($options)->with(...$items);
    $result = $className === null ? $ai->run() : $ai->runCasted($className);

    if (!$fileWasWritten) {
        throw new RuntimeException('AI response did not write the target file through write_file_content.');
    }

    return $result;
}
