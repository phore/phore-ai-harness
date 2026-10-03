<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context\Traits;

use InvalidArgumentException;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\OutputFormat\StructPatchOutput;
use Phore\AiHarness\Patch\StructPatcher;
use Phore\AiHarness\Patch\StructPatchPrompt;
use Phore\AiHarness\Patch\StructSchemaValidator;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\ToolType;
use Phore\JsonPatch\JsonPatchApplier;
use Phore\JsonPatch\JsonValue;
use Phore\JsonPatch\PatchApplyOptions;
use Phore\JsonPatch\PatchConflictException;
use Phore\JsonPatch\StableArrayView;

trait StructTrait
{
    /**
     * Generate a typed object from a class name, or patch an existing instance.
     * Object input preserves the existing bounded JSON Patch/schema safeguards
     * and returns a new object; it never mutates or persists the original.
     * Shared context callbacks can clarify the task before a patch is produced.
     * Explicit prompt tools remain unsupported for object-edit mode.
     *
     * @template T of object
     * @param string|PromptType|ToolType|array $prompts Instructions and sources.
     * @param class-string<T>|T $input Output class or existing editing target.
     * @param array<string, mixed> $options Common and struct-patch options.
     * @return T|\Phore\JsonPatch\PatchApplyResult Metadata when dry_run/return_patch is set.
     * @throws InvalidArgumentException For invalid options, class or prompt tools.
     * @throws \Phore\JsonPatch\PatchValidationException For an invalid patch.
     * @example $answer = (new AiContext())->struct('Extract the answer.', Answer::class);
     * @example $edited = $context->struct('Correct the title.', $original);
     * @see \Phore\AiHarness\AiContext
     * @see StructPatcher
     */
    public function struct(string|PromptType|ToolType|array $prompts, string|object $input, array $options = []): object
    {
        if (is_string($input)) {
            return $this->executeAi(
                Toolkit::normalizePromptItems($prompts),
                $options,
                static fn (PhoreAi $ai): object => $ai->runCasted($input),
            );
        }
        return $this->patchStruct($prompts, $input, $options);
    }

    private function patchStruct(string|PromptType|ToolType|array $prompts, object $target, array $options): object
    {
        // Bestehende Patch-Policies und die Trennung von Daten und Tools bleiben erhalten.
        if (($options['mode'] ?? 'patch') !== 'patch') {
            throw new InvalidArgumentException('Only explicit patch mode is supported.');
        }
        $addressing = $options['addressing'] ?? 'pointer';
        if (!in_array($addressing, ['pointer', 'stable'], true)) {
            throw new InvalidArgumentException('addressing must be pointer or stable.');
        }
        $policy = PatchApplyOptions::fromArray($options);
        $items = Toolkit::normalizePromptItems($prompts);
        foreach ($items as $item) {
            if ($item instanceof ToolType) {
                throw new InvalidArgumentException('Struct patch generation does not accept tools.');
            }
        }
        $snapshot = JsonValue::copy($target);
        JsonPatchApplier::checkDocument($snapshot, $policy);
        $hash = JsonValue::hash($snapshot);
        if ($policy->expectedHash !== null && !hash_equals($policy->expectedHash, $hash)) {
            throw new PatchConflictException('hash_conflict');
        }
        $schema = StructSchemaValidator::forClass($target::class);
        (new StructSchemaValidator())->assertValid($snapshot, $schema);
        $view = $addressing === 'stable'
            ? (new StableArrayView($options['identity_pointers'] ?? []))->encode($snapshot)
            : $snapshot;
        JsonPatchApplier::checkDocument($view, $policy);
        $items[] = new SystemPrompt(StructPatchPrompt::instructions($addressing, $policy, $view));
        $items[] = new TextPrompt(JsonValue::encode([
            'target' => $view, 'target_schema' => $schema, 'expected_hash' => $hash,
        ]));
        $format = new StructPatchOutput($policy);
        $output = $this->executeAi($items, $options, static fn (PhoreAi $ai): string => $ai->run(), $format);

        // Erst nach der Modellantwort wird lokal validiert; es gibt keinen Rewrite-Fallback.
        $patch = $format->parse($output);
        $options['expected_hash'] = $hash;
        $result = (new StructPatcher())->apply($target, $patch, $options);
        return ($options['dry_run'] ?? false) || ($options['return_patch'] ?? false) ? $result : $result->value;
    }
}
