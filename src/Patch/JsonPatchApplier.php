<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

final class JsonPatchApplier
{
    /** The optional validator runs once, on a detached final value, before release. */
    public function apply(mixed $target, JsonPatch $patch, ?PatchApplyOptions $options = null, ?callable $validator = null): PatchApplyResult
    {
        $options ??= new PatchApplyOptions();
        if (count($patch->operations) > $options->maxOperations || strlen(JsonValue::encode($patch)) > $options->maxPatchBytes) {
            throw new PatchLimitException('patch_limit');
        }
        $document = JsonValue::copy($target);
        self::checkDocument($document, $options);
        $oldHash = JsonValue::hash($document);
        if ($options->expectedHash !== null && !hash_equals($options->expectedHash, $oldHash)) {
            throw new PatchConflictException('hash_conflict');
        }
        $exists = true;
        $protected = [];
        foreach ($options->forbiddenPaths as $path) {
            $protected[$path] = $this->protectedValue($document, $path);
        }
        $changed = [];
        foreach ($patch->operations as $index => $operation) {
            try {
                $this->checkPolicy($document, $operation, $options, $patch->operations[$index - 1] ?? null);
                if (!$exists && !($operation->op === 'add' && $operation->path === '')) {
                    throw new PatchValidationException('missing_document');
                }
                switch ($operation->op) {
                    case 'test':
                        if (!JsonValue::equal(JsonPointer::get($document, $operation->path), $operation->value())) {
                            throw new PatchTestFailedException('test_failed');
                        }
                        break;
                    case 'move':
                    case 'copy':
                        if ($operation->op === 'move' && $operation->from !== $operation->path && JsonPointer::contains($operation->from, $operation->path)) {
                            throw new PatchValidationException('move_into_descendant');
                        }
                        $value = JsonValue::copy(JsonPointer::get($document, $operation->from));
                        if ($operation->op === 'move') {
                            if ($operation->from === $operation->path) {
                                break;
                            }
                            $this->mutate($document, JsonPointer::tokens($operation->from), 'remove', null, $exists);
                            $changed[] = $operation->from;
                        }
                        $this->mutate($document, JsonPointer::tokens($operation->path), 'add', $value, $exists);
                        $changed[] = $operation->path;
                        break;
                    default:
                        $this->mutate($document, JsonPointer::tokens($operation->path), $operation->op, $operation->value(), $exists);
                        $changed[] = $operation->path;
                }
                self::checkDocument($document, $options);
                foreach ($protected as $path => $before) {
                    if (!JsonValue::equal($before, $this->protectedValue($document, $path))) {
                        throw new PatchValidationException('path_forbidden');
                    }
                }
            } catch (PatchValidationException $exception) {
                throw $exception->atOperation($index, $operation);
            }
        }
        if ($validator !== null) {
            if (!$exists || $validator(JsonValue::copy($document)) === false) {
                throw new PatchValidationException('final_validation_failed');
            }
        }
        return new PatchApplyResult(
            $options->dryRun ? 'dry_run' : 'applied', $oldHash,
            $exists ? JsonValue::hash($document) : hash('sha256', 'undefined'),
            count($patch->operations), array_values(array_unique($changed)), $document, $exists, $patch,
        );
    }

    private function protectedValue(mixed $document, string $path): array
    {
        try {
            return [true, JsonValue::copy(JsonPointer::get($document, $path))];
        } catch (PatchValidationException) {
            return [false];
        }
    }

    public static function checkDocument(mixed $value, PatchApplyOptions $options, int $depth = 0): void
    {
        if ($depth > $options->maxDepth) {
            throw new PatchLimitException('depth_limit');
        }
        if ($depth === 0 && strlen(JsonValue::encode($value)) > $options->maxDocumentBytes) {
            throw new PatchLimitException('document_limit');
        }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $child) {
                self::checkDocument($child, $options, $depth + 1);
            }
        }
    }

    private function checkPolicy(mixed $document, JsonPatchOperation $operation, PatchApplyOptions $options, ?JsonPatchOperation $previous): void
    {
        if (!in_array($operation->op, $options->allowedOperations, true)) {
            throw new PatchValidationException('operation_forbidden');
        }
        foreach (array_filter([$operation->path, $operation->from], static fn ($p) => $p !== null) as $path) {
            if (count(JsonPointer::tokens($path)) > $options->maxDepth) {
                throw new PatchLimitException('depth_limit');
            }
            foreach ($options->forbiddenPaths as $forbidden) {
                // Also prevent a parent replacement/copy/test from bypassing protection.
                if (JsonPointer::contains($forbidden, $path) || JsonPointer::contains($path, $forbidden)) {
                    throw new PatchValidationException('path_forbidden');
                }
            }
        }
        if (!$options->allowRootReplacement && (($operation->path === '' && $operation->op !== 'test')
            || ($operation->op === 'move' && $operation->from === ''))) {
            throw new PatchValidationException('root_change_forbidden');
        }
        if ($options->requireTests === 'none' || !in_array($operation->op, ['remove', 'replace', 'move'], true)) {
            return;
        }
        $path = $operation->op === 'move' ? $operation->from : $operation->path;
        $arrayElement = $this->arrayElementPath($document, $path);
        if ($options->requireTests === 'arrays' && $arrayElement === null) {
            return;
        }
        if ($previous === null || $previous->op !== 'test') {
            throw new PatchValidationException('test_required');
        }
        // A test may cover the whole mutation, or an identity inside the exact array element.
        if (!JsonPointer::contains($previous->path, $path)
            && !($arrayElement === $path && JsonPointer::contains($path, $previous->path))) {
            throw new PatchValidationException('test_required');
        }
    }

    private function arrayElementPath(mixed $document, string $path): ?string
    {
        $prefix = '';
        $element = null;
        foreach (JsonPointer::tokens($path) as $token) {
            $prefix .= '/' . JsonPointer::escape($token);
            if (is_array($document)) {
                $element = $prefix;
            }
            $document = JsonPointer::get($document, '/' . JsonPointer::escape($token));
        }
        return $element;
    }

    /** @param list<string> $tokens */
    private function mutate(mixed &$document, array $tokens, string $op, mixed $value, bool &$exists): void
    {
        if ($tokens === []) {
            $document = $op === 'remove' ? null : $value;
            $exists = $op !== 'remove';
            return;
        }
        $last = array_pop($tokens);
        $parent =& $document;
        foreach ($tokens as $token) {
            if ($parent instanceof \stdClass && property_exists($parent, $token)) {
                $parent =& $parent->{$token};
            } elseif (is_array($parent)) {
                $index = JsonPointer::index($token, count($parent));
                $parent =& $parent[$index];
            } else {
                throw new PatchValidationException('missing_parent');
            }
        }
        if ($parent instanceof \stdClass) {
            if ($op !== 'add' && !property_exists($parent, $last)) {
                throw new PatchValidationException('missing_path');
            }
            if ($op === 'remove') {
                unset($parent->{$last});
            } else {
                $parent->{$last} = $value;
            }
        } elseif (is_array($parent)) {
            $index = JsonPointer::index($last, count($parent), $op === 'add');
            if ($op === 'replace') {
                $parent[$index] = $value;
            } else {
                array_splice($parent, $index, $op === 'remove' ? 1 : 0, $op === 'remove' ? [] : [$value]);
            }
        } else {
            throw new PatchValidationException('missing_parent');
        }
    }
}
