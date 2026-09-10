<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

use Phore\Schema\Parser\SchemaParser;

final class StructPatcher
{
    /** Always returns a new instance (or dry-run metadata), never mutates target. */
    public function apply(object $target, JsonPatch $patch, array $options = []): PatchApplyResult
    {
        $policy = PatchApplyOptions::fromArray($options);
        $addressing = $options['addressing'] ?? 'pointer';
        if (!in_array($addressing, ['pointer', 'stable'], true)) {
            throw new \InvalidArgumentException('addressing must be pointer or stable.');
        }
        $original = JsonValue::copy($target);
        $oldHash = JsonValue::hash($original);
        if ($policy->expectedHash !== null && !hash_equals($policy->expectedHash, $oldHash)) {
            throw new PatchConflictException('hash_conflict');
        }
        $classSchema = (new SchemaParser())->parseClass($target::class);
        $jsonSchema = StructSchemaValidator::forClass($target::class);
        $validator = new StructSchemaValidator();
        $validator->assertValid($original, $jsonSchema);
        $codec = new StableArrayView($options['identity_pointers'] ?? []);
        $view = $addressing === 'stable' ? $codec->encode($original) : $original;
        // The public hash is always the original struct hash, not the transport hash.
        $applyOptions = $options;
        unset($applyOptions['expected_hash']);
        $result = (new JsonPatchApplier())->apply($view, $patch, PatchApplyOptions::fromArray($applyOptions));
        if (!$result->documentExists) {
            throw new PatchValidationException('struct_root_removed');
        }
        $final = $addressing === 'stable' ? $codec->decode($result->value) : $result->value;
        JsonPatchApplier::checkDocument($final, $policy);
        $validator->assertValid($final, $jsonSchema);
        try {
            $object = $classSchema->hydrate($final);
        } catch (\Throwable) {
            throw new PatchValidationException('hydration_failed');
        }
        $hydrated = JsonValue::copy($object);
        $validator->assertValid($hydrated, $jsonSchema);
        // Constructors may enforce invariants, but must not silently rewrite supplied fields.
        $this->assertSuppliedValuesPreserved($final, $hydrated);
        if (!hash_equals($oldHash, JsonValue::hash($target))) {
            throw new PatchConflictException('hash_conflict');
        }
        return new PatchApplyResult($policy->dryRun ? 'dry_run' : 'applied', $oldHash, JsonValue::hash($hydrated),
            $result->operationCount, $result->changedPaths, $object, true, ($options['return_patch'] ?? false) ? $patch : null);
    }

    private function assertSuppliedValuesPreserved(mixed $expected, mixed $actual): void
    {
        if ($expected instanceof \stdClass && $actual instanceof \stdClass) {
            foreach ($expected as $key => $value) {
                if (!property_exists($actual, $key)) {
                    throw new PatchValidationException('hydration_changed_value');
                }
                $this->assertSuppliedValuesPreserved($value, $actual->{$key});
            }
        } elseif (is_array($expected) && is_array($actual) && count($expected) === count($actual)) {
            foreach ($expected as $key => $value) {
                $this->assertSuppliedValuesPreserved($value, $actual[$key]);
            }
        } elseif (!JsonValue::equal($expected, $actual)) {
            throw new PatchValidationException('hydration_changed_value');
        }
    }
}
