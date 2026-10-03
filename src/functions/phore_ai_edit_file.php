<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\Edit\FileEditException;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Create/edit explicitly allowed UTF-8 text files through a shared edit engine.
 * The legacy arguments, their names and the final summary return are preserved.
 * A target is one path or a non-empty list. Existing content is attached once;
 * unreadable files fail explicitly instead of being mistaken for empty files.
 * One callback contains all edits per file. A failed file is left unchanged;
 * other successful files remain written and are not replayed on correction.
 * The shared five-round limit bounds all callbacks, including user questions.
 *
 * @template T of object
 * @param string|PromptType|ToolType|array $prompts Instructions and sources/tools.
 * @param string|list<string> $filenames Existing or new target paths; parent directories must exist.
 * @param class-string<T>|null $className Class for the final summary, not file contents.
 * @param array<string, mixed> $options Existing common options plus ai_context (ID/object/null).
 * @return T|string Hydrated summary or plain text after a successful edit/no-op.
 * @throws InvalidArgumentException For invalid target arguments or output class.
 * @throws FileEditException For incomplete edits, with per-file outcomes.
 * @throws RuntimeException For unreadable files or missing write callback.
 * @example $summary = phore_ai_edit_file('Correct spelling.', ['draft.md', 'intro.md'], options: ['ai_context' => 'default']);
 * @see AiContext::file()
 * @see \Phore\AiHarness\Edit\TextEditEngine
 */
function phore_ai_edit_file(string|PromptType|ToolType|array $prompts, string|array $filenames, ?string $className = null, array $options = []): object|string
{
    return AiContextRegistry::resolve($options)->file($prompts, $filenames, ['output_class' => $className] + $options);
}
