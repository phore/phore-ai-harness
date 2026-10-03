<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context\Traits;

use InvalidArgumentException;
use Phore\AiHarness\Edit\FileBatchEditor;
use Phore\AiHarness\Edit\FileEditException;
use Phore\AiHarness\Edit\TextEditEngine;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\CallbackRoundLimitException;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ToolType;

trait FileTrait
{
    /**
     * Create/edit one or several explicitly allowed UTF-8 text files.
     * Existing contents are attached as source data. Missing files can be created
     * in an existing directory. One write_files callback batches replacements;
     * a validation failure leaves that file untouched but keeps other successes.
     * Returns a final summary, optionally hydrated via options['output_class'].
     *
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param string|list<string> $input Target path(s), not the text contents.
     * @param array<string, mixed> $options Common options and optional output_class.
     * @throws InvalidArgumentException For invalid targets or output class.
     * @throws FileEditException For unresolved edits, with per-file outcomes.
     * @throws \RuntimeException For unreadable input or missing callback completion.
     * @example $summary = (new AiContext())->file('Correct spelling.', ['draft.md', 'intro.md']);
     * @see \Phore\AiHarness\AiContext
     * @see TextEditEngine
     */
    public function file(string|PromptType|ToolType|array $prompts, string|array $input, array $options = []): object|string
    {
        $className = $options['output_class'] ?? null;
        if ($className !== null && (!is_string($className) || !class_exists($className))) {
            throw new InvalidArgumentException('Output class does not exist: ' . (is_string($className) ? $className : '(invalid type)'));
        }
        $editor = new FileBatchEditor($input);
        $items = [...Toolkit::normalizePromptItems($prompts), ...$editor->prompts()];
        $items[] = new SystemPrompt(
            TextEditEngine::INSTRUCTIONS . ' Edit only the supplied target files. Save changes in one write_files batch using their targetFileN aliases. For a missing file use a single search=null edit. If some files fail, retry only the failed retryable files; successful files are final for this invocation. After success return only a concise summary, not the complete files.',
        );
        $items[] = new CallbackTool([$editor, 'write'], 'write_files', 'Apply edits to supplied target files. Each entry contains filename (targetFileN alias) and edits (search/replacement pairs). Each file is validated independently; successful files remain applied.');

        // Gemeinsames Rundenlimit verwenden; Teilerfolge auch im Fehlerfall offenlegen.
        try {
            $result = $this->executeAi(
                $items,
                $options,
                static fn (PhoreAi $ai): object|string => $className === null ? $ai->run() : $ai->runCasted($className),
            );
        } catch (CallbackRoundLimitException $error) {
            throw new FileEditException($editor->results(), $error);
        }
        $editor->assertComplete();
        return $result;
    }
}
