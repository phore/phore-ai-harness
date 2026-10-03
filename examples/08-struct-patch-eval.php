<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phore\JsonPatch\{JsonValue, PatchValidationException};
use Phore\AiHarness\Patch\{StructSchemaValidator};

final class StructPatchEvalItem
{
    public function __construct(public string $id, public string $name) {}
}

final class StructPatchEvalDocument
{
    /** @var list<StructPatchEvalItem> */
    public array $items;

    public function __construct(public string $title, array $items, public ?string $note = null)
    {
        $this->items = $items;
    }
}

// Explicit manual invocation only: 3 sizes x 3 modes x 3 tasks = 27 paid requests.
// JSONL metrics are measured, never estimated. Redirect stdout to retain a run.
foreach ([3, 30, 300] as $size) {
    $items = [];
    for ($index = 0; $index < $size; $index++) {
        $items[] = new StructPatchEvalItem('id-' . $index, 'Name ' . $index);
    }
    $target = new StructPatchEvalDocument('Original', $items, 'optional');
    foreach (['field','list','noop'] as $task) {
        $expected = unserialize(serialize($target));
        $instruction = match ($task) {
            'field' => 'Set title to "Edited" and note to null. Keep every item unchanged.',
            'list' => 'Remove items id-0 and id-2; rename id-1 to "Changed". Keep all other data and the order unchanged.',
            'noop' => 'Keep the entire document unchanged.',
        };
        if ($task === 'field') {
            $expected->title = 'Edited';
            $expected->note = null;
        } elseif ($task === 'list') {
            $expected->items[1]->name = 'Changed';
            $expected->items = array_values(array_filter($expected->items, static fn ($item) => !in_array($item->id, ['id-0','id-2'], true)));
        }
        foreach (['replace','pointer','stable'] as $mode) {
            $start = hrtime(true);
            $requestBefore = get_last_ai_request();
            $responseBefore = get_last_ai_response();
            $actual = null;
            $error = null;
            $schemaValid = false;
            try {
                $actual = $mode === 'replace'
                    ? phore_ai_struct([$instruction, 'Target: ' . JsonValue::encode($target)], StructPatchEvalDocument::class)
                    : phore_ai_edit_struct($instruction, $target, ['addressing'=>$mode]);
                (new StructSchemaValidator())->assertValid(JsonValue::copy($actual), StructSchemaValidator::forClass(StructPatchEvalDocument::class));
                $schemaValid = true;
            } catch (Throwable $exception) {
                $error = $exception instanceof PatchValidationException ? $exception->errorCode : $exception::class;
            }
            $response = get_last_ai_response();
            $usage = $response !== $responseBefore ? ($response?->body['usage'] ?? []) : [];
            echo JsonValue::encode([
                'size'=>$size, 'task'=>$task, 'mode'=>$mode,
                'exact_match'=>$actual !== null && JsonValue::equal(JsonValue::copy($expected), JsonValue::copy($actual)),
                'application_success'=>$actual !== null, 'schema_valid'=>$schemaValid,
                'unexpected_paths'=>$actual === null ? null : differentPaths(JsonValue::copy($expected), JsonValue::copy($actual)),
                'input_tokens'=>$usage['input_tokens'] ?? null, 'output_tokens'=>$usage['output_tokens'] ?? null,
                'requests'=>get_last_ai_request() !== $requestBefore ? 1 : 0,
                'latency_ms'=>(hrtime(true)-$start)/1000000, 'repairs'=>0, 'error'=>$error,
            ]) . PHP_EOL;
        }
    }
}

function differentPaths(mixed $expected, mixed $actual, string $path = ''): array
{
    if (JsonValue::equal($expected, $actual)) {
        return [];
    }
    if (($expected instanceof stdClass && $actual instanceof stdClass) || (is_array($expected) && is_array($actual))) {
        $left = (array) $expected;
        $right = (array) $actual;
        $paths = [];
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            $child = $path . '/' . \Phore\JsonPatch\JsonPointer::escape((string) $key);
            $paths = [...$paths, ...(!array_key_exists($key, $left) || !array_key_exists($key, $right)
                ? [$child] : differentPaths($left[$key], $right[$key], $child))];
        }
        return $paths;
    }
    return [$path];
}
