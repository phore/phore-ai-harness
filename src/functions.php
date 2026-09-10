<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\DefaultSystemPrompt;
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
 * Edits an existing struct using one bounded model batch. The original is never mutated.
 * See docs/struct-patch.md for policies, stable addressing and result metadata.
 * ToolType inputs are rejected: patch generation must not invoke side-effecting tools.
 *
 * @template T of object
 * @param T $target
 * @return T|\Phore\AiHarness\Patch\PatchApplyResult
 */
function phore_ai_edit_struct(string|PromptType|ToolType|array $prompts, object $target, array $options = []): object
{
    if (($options['mode'] ?? 'patch') !== 'patch') {
        throw new InvalidArgumentException('Only explicit patch mode is supported.');
    }
    $addressing = $options['addressing'] ?? 'pointer';
    if (!in_array($addressing, ['pointer', 'stable'], true)) {
        throw new InvalidArgumentException('addressing must be pointer or stable.');
    }
    $policy = \Phore\AiHarness\Patch\PatchApplyOptions::fromArray($options);
    $items = Toolkit::normalizePromptItems($prompts);
    foreach ($items as $item) {
        if ($item instanceof ToolType) {
            throw new InvalidArgumentException('Struct patch generation does not accept tools.');
        }
    }
    $snapshot = \Phore\AiHarness\Patch\JsonValue::copy($target);
    \Phore\AiHarness\Patch\JsonPatchApplier::checkDocument($snapshot, $policy);
    $hash = \Phore\AiHarness\Patch\JsonValue::hash($snapshot);
    if ($policy->expectedHash !== null && !hash_equals($policy->expectedHash, $hash)) {
        throw new \Phore\AiHarness\Patch\PatchConflictException('hash_conflict');
    }
    $schema = \Phore\AiHarness\Patch\StructSchemaValidator::forClass($target::class);
    (new \Phore\AiHarness\Patch\StructSchemaValidator())->assertValid($snapshot, $schema);
    $view = $addressing === 'stable'
        ? (new \Phore\AiHarness\Patch\StableArrayView($options['identity_pointers'] ?? []))->encode($snapshot)
        : $snapshot;
    \Phore\AiHarness\Patch\JsonPatchApplier::checkDocument($view, $policy);
    $items[] = new SystemPrompt(\Phore\AiHarness\Patch\StructPatchPrompt::instructions($addressing, $policy, $view));
    $items[] = new \Phore\AiHarness\PromptType\TextPrompt(\Phore\AiHarness\Patch\JsonValue::encode([
        'target' => $view, 'target_schema' => $schema, 'expected_hash' => $hash,
    ]));
    $format = new \Phore\AiHarness\OutputFormat\StructPatchOutput($policy);
    $output = Toolkit::createAi($options)->with(...$items)->withOutput($format)->run();
    $patch = $format->parse($output);
    // Detect changes during the model call even when no explicit expected_hash was supplied.
    $options['expected_hash'] = $hash;
    $result = (new \Phore\AiHarness\Patch\StructPatcher())->apply($target, $patch, $options);
    return ($options['dry_run'] ?? false) || ($options['return_patch'] ?? false) ? $result : $result->value;
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
