<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Edit a typed object atomically using one bounded, AI-generated JSON Patch batch.
 * The original object is never mutated. The candidate is validated and hydrated
 * before it is returned. Tool inputs are rejected, even though the shared native
 * signature accepts ToolType. No repair requests or fallback replacements run.
 * See docs/struct-patch.md for stable addressing, schema support and policy details.
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
 * - mode: Only "patch" is supported (default); other modes are rejected.
 * - addressing: "pointer" (default) uses sequential JSON Pointer indices;
 *   "stable" uses the stable-ID array transport view.
 * - max_operations: Maximum operations per batch; default 100, minimum 0.
 * - allowed_operations: Default ["add", "remove", "replace", "test"]. Add "move"
 *   and/or "copy" explicitly to permit them; any operation outside the list is rejected.
 * - max_patch_bytes: Maximum raw provider envelope and serialized patch bytes;
 *   default 65536, minimum 1.
 * - max_document_bytes: Maximum input, transport, intermediate and final JSON bytes;
 *   default 4194304, minimum 1.
 * - max_depth: Maximum document and pointer depth; default 64, minimum 1.
 * - require_tests: "none" (default), "arrays" for positional remove/replace and move
 *   sources, or "all" for every remove/replace/move source. Guards must immediately
 *   precede the edit and cover its path or the identity of that exact array element.
 * - expected_hash: Expected lowercase SHA-256 JsonValue::hash($target), or null
 *   (default). A mismatch rejects the edit. A snapshot hash is also rechecked across
 *   the model call; external persistence still requires its own transaction.
 * - forbidden_paths: Protected JSON Pointer prefixes in the selected addressing
 *   view; default []. Overlapping reads/writes and indirect array shifts are blocked.
 * - allow_root_replacement: Permit root replacement; default false. A typed struct
 *   root cannot be removed, even when this option is true.
 * - identity_pointers: Map from original array pointer to identity pointer within
 *   each element, e.g. ["/items" => "/id"]; default []. Used with stable addressing;
 *   explicit identities must exist, be scalar and be unique within the array.
 * - dry_run: Default false. Still calls the model and validates/hydrates a candidate,
 *   but returns PatchApplyResult with status "dry_run". Neither mode persists data.
 * - return_patch: Default false. Return PatchApplyResult with its native patch;
 *   the edited typed object is in result->value. With dry_run alone, patch is null.
 *
 * Example (after requiring vendor/autoload.php and configuring credentials):
 * <code>
 * final class Article {
 *     public function __construct(public string $title) {}
 * }
 * $original = new Article('Draft');
 * $edited = phore_ai_edit_struct('Set the title to Published.', $original, [
 *     'addressing' => 'pointer',
 *     'allowed_operations' => ['test', 'replace'],
 *     'max_operations' => 4,
 *     'require_tests' => 'all',
 * ]);
 * // $edited is a new Article; $original->title is still 'Draft'.
 * </code>
 *
 * @template T of object
 * @param string|PromptType|array<int, string|PromptType> $prompts Editing instructions and optional prompt data; ToolType inputs are rejected.
 * @param T $target Existing schema-compatible object; its class determines the result type.
 * @param array{
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     reasoning?: array<string, mixed>|null,
 *     timeout?: positive-int,
 *     connect_timeout?: positive-int,
 *     debug_log?: bool|LoggerInterface,
 *     mode?: 'patch',
 *     addressing?: 'pointer'|'stable',
 *     max_operations?: non-negative-int,
 *     allowed_operations?: list<'add'|'remove'|'replace'|'move'|'copy'|'test'>,
 *     max_patch_bytes?: positive-int,
 *     max_document_bytes?: positive-int,
 *     max_depth?: positive-int,
 *     require_tests?: 'none'|'arrays'|'all',
 *     expected_hash?: string|null,
 *     forbidden_paths?: list<string>,
 *     allow_root_replacement?: bool,
 *     identity_pointers?: array<string, string>,
 *     dry_run?: bool,
 *     return_patch?: bool
 * } $options Optional settings; omitted keys use the defaults described above.
 * @return T|\Phore\JsonPatch\PatchApplyResult New typed object, or result metadata when dry_run or return_patch is true.
 * @throws InvalidArgumentException For invalid options or tool inputs.
 * @throws \Phore\AiHarness\Client\AiRequestException If the provider request fails.
 * @throws \Phore\JsonPatch\PatchValidationException If the patch, schema, hydration, limits, guard or hash check fails.
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
    $policy = \Phore\JsonPatch\PatchApplyOptions::fromArray($options);
    $items = Toolkit::normalizePromptItems($prompts);
    foreach ($items as $item) {
        if ($item instanceof ToolType) {
            throw new InvalidArgumentException('Struct patch generation does not accept tools.');
        }
    }
    $snapshot = \Phore\JsonPatch\JsonValue::copy($target);
    \Phore\JsonPatch\JsonPatchApplier::checkDocument($snapshot, $policy);
    $hash = \Phore\JsonPatch\JsonValue::hash($snapshot);
    if ($policy->expectedHash !== null && !hash_equals($policy->expectedHash, $hash)) {
        throw new \Phore\JsonPatch\PatchConflictException('hash_conflict');
    }
    $schema = \Phore\AiHarness\Patch\StructSchemaValidator::forClass($target::class);
    (new \Phore\AiHarness\Patch\StructSchemaValidator())->assertValid($snapshot, $schema);
    $view = $addressing === 'stable'
        ? (new \Phore\JsonPatch\StableArrayView($options['identity_pointers'] ?? []))->encode($snapshot)
        : $snapshot;
    \Phore\JsonPatch\JsonPatchApplier::checkDocument($view, $policy);
    $items[] = new SystemPrompt(\Phore\AiHarness\Patch\StructPatchPrompt::instructions($addressing, $policy, $view));
    $items[] = new \Phore\AiHarness\PromptType\TextPrompt(\Phore\JsonPatch\JsonValue::encode([
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
