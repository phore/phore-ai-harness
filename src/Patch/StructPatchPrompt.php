<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

final class StructPatchPrompt
{
    public static function instructions(string $addressing, PatchApplyOptions $policy, mixed $view): string
    {
        $text = 'Edit only the supplied target struct. Return exactly one JSON object with unsupported and operations. '
            . 'Use RFC 6902 operations and RFC 6901 JSON Pointer paths (~0 escapes ~; ~1 escapes /). '
            . 'Operations execute sequentially against the state produced by previous operations. '
            . 'Change only requested fields and logically necessary dependent fields. Do not copy unchanged subtrees. '
            . 'Do not change the schema or invent paths. If unsafe or unsupported, return unsupported=true and operations=[]. '
            . 'Each operation has op, path, value_json, from. For add/replace/test encode the native JSON value as a JSON string in value_json '
            . '(a string value includes its JSON quotes; a null value is the string "null"). For remove/move/copy use value_json=null. '
            . 'Use from only for move/copy, otherwise null. '
            . 'Policy: ' . JsonValue::encode([
                'allowed_operations' => $policy->allowedOperations, 'max_operations' => $policy->maxOperations,
                'max_patch_bytes' => $policy->maxPatchBytes, 'allow_root_replacement' => $policy->allowRootReplacement,
                'forbidden_paths' => $policy->forbiddenPaths, 'require_tests' => $policy->requireTests,
            ]);
        $text .= $addressing === 'stable'
            ? ' Every target array is represented by an object with $values (ID-to-element map) and $order (ordered IDs). '
                . 'Keep existing transport IDs stable, even when editing identity fields. Edit elements through /$values/<id>. '
                . 'To remove/add an element update both $values and $order in the same batch; reorder by replacing $order. '
                . 'New transport IDs must be unique and nonempty. Wrap new nested arrays in the same representation. '
                . 'The $order arrays themselves use ordinary sequential JSON Pointer indices. No duplicates, missing IDs or orphan values are permitted.'
            : ' Use zero-based array indices and - only for appending. Insert shifts subsequent indices right; remove shifts them left. '
                . 'For multiple removals from the original list, remove higher indices first. Before positional remove/replace use a test '
                . 'on the value being changed, or on an identity inside the exact element being removed/replaced.';
        $text .= '\nExamples based on the supplied data (illustrations only; do not execute without a matching user request): '
            . JsonValue::encode(self::examples($view, $addressing));
        return $text;
    }

    private static function examples(mixed $view, string $addressing): array
    {
        $field = null;
        $list = null;
        self::findExamples($view, '', $addressing, $field, $list);
        $examples = [['unsupported' => false, 'operations' => []]];
        if ($field !== null) {
            [$path, $value] = $field;
            $examples[] = ['unsupported' => false, 'operations' => [self::operation('replace', $path, $value)]];
        }
        if ($list !== null) {
            [$path, $value] = $list;
            if ($addressing === 'stable') {
                $order = $value->{'$order'};
                $id = $order[0];
                $itemPath = $path . '/$values/' . JsonPointer::escape($id);
                $newId = 'new_example';
                while (property_exists($value->{'$values'}, $newId)) {
                    $newId .= '_';
                }
                $examples[] = ['unsupported' => false, 'operations' => [
                    self::operation('test', $itemPath, $value->{'$values'}->{$id}),
                    self::operation('remove', $itemPath),
                    self::operation('add', $path . '/$values/' . $newId, $value->{'$values'}->{$id}),
                    self::operation('replace', $path . '/$order', [...array_slice($order, 1), $newId]),
                ]];
            } else {
                $examples[] = ['unsupported' => false, 'operations' => [
                    self::operation('test', $path . '/0', $value[0]),
                    self::operation('remove', $path . '/0'),
                    self::operation('add', $path . '/-', $value[0]),
                ]];
            }
        }
        return $examples;
    }

    private static function operation(string $op, string $path, mixed $value = null): array
    {
        return ['op' => $op, 'path' => $path, 'value_json' => $op === 'remove' ? null : JsonValue::encode($value), 'from' => null];
    }

    private static function findExamples(mixed $value, string $path, string $addressing, ?array &$field, ?array &$list): void
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            $field ??= [$path, $value];
            return;
        }
        if ($addressing === 'stable' && $value instanceof \stdClass && isset($value->{'$order'}, $value->{'$values'})) {
            if ($value->{'$order'} !== []) {
                $list ??= [$path, $value];
                foreach ($value->{'$values'} as $id => $item) {
                    self::findExamples($item, $path . '/$values/' . JsonPointer::escape((string) $id), $addressing, $field, $list);
                }
            }
            return;
        }
        if (is_array($value) && $value !== []) {
            $list ??= [$path, $value];
        }
        foreach ($value as $key => $child) {
            self::findExamples($child, $path . '/' . JsonPointer::escape((string) $key), $addressing, $field, $list);
        }
    }
}
