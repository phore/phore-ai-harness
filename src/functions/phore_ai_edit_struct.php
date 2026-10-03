<?php

declare(strict_types=1);

use Phore\AiHarness\AiContext;
use Phore\AiHarness\Context\AiContextRegistry;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Patch a typed object through the unchanged legacy signature and JSON Patch contract.
 * The original is never mutated. Existing mode, addressing, identity_pointers,
 * operation/size/depth limits, expected_hash, forbidden_paths, require_tests,
 * allow_root_replacement, dry_run and return_patch options remain supported.
 * No automatic patch repair or full-object fallback is performed. Explicit
 * prompt tools remain rejected; shared AiContext callbacks may clarify the task.
 * Options also accept ai_context as an ID/object/null and common client settings.
 *
 * @template T of object
 * @param string|PromptType|ToolType|array $prompts Editing instructions and sources; direct ToolType items are rejected.
 * @param T $target Existing schema-compatible instance.
 * @param array<string, mixed> $options Common context and documented struct-patch policies.
 * @return T|\Phore\JsonPatch\PatchApplyResult New instance, or metadata for dry_run/return_patch.
 * @throws InvalidArgumentException For invalid options or explicit prompt tools.
 * @throws \Phore\JsonPatch\PatchValidationException For an invalid patch, schema or hash.
 * @example $edited = phore_ai_edit_struct('Correct the title.', $original, ['ai_context' => 'default']);
 * @see AiContext::struct()
 * @see \Phore\AiHarness\Patch\StructPatcher
 */
function phore_ai_edit_struct(string|PromptType|ToolType|array $prompts, object $target, array $options = []): object
{
    return AiContextRegistry::resolve($options)->struct($prompts, $target, $options);
}
