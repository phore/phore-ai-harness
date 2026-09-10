<?php

declare(strict_types=1);

namespace Phore\AiHarness\OutputFormat;

use Phore\AiHarness\Patch\JsonPatch;
use Phore\AiHarness\Patch\JsonValue;
use Phore\AiHarness\Patch\PatchApplyOptions;
use Phore\AiHarness\Patch\PatchLimitException;
use Phore\AiHarness\Patch\PatchValidationException;

/** Strict provider envelope; value_json is decoded to native RFC 6902 value locally. */
final readonly class StructPatchOutput implements OutputFormat
{
    public function __construct(public PatchApplyOptions $options = new PatchApplyOptions()) {}

    public function type(): string
    {
        return 'struct_patch';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unsupported' => ['type' => 'boolean'],
                'operations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'op' => ['type' => 'string', 'enum' => $this->options->allowedOperations],
                            'path' => ['type' => 'string'],
                            'value_json' => ['type' => ['string', 'null'], 'description' => 'A complete JSON-encoded value for add/replace/test, including JSON quotes for strings; null for other operations.'],
                            'from' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['op', 'path', 'value_json', 'from'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['unsupported', 'operations'],
            'additionalProperties' => false,
        ];
    }

    public function toArray(): array
    {
        return ['type' => $this->type(), 'jsonSchema' => $this->jsonSchema()];
    }

    public function parse(string $output): JsonPatch
    {
        if (strlen($output) > $this->options->maxPatchBytes) {
            throw new PatchLimitException('patch_limit');
        }
        $data = JsonValue::decode($output);
        if (!$data instanceof \stdClass || count(get_object_vars($data)) !== 2
            || !isset($data->unsupported, $data->operations) || !is_bool($data->unsupported)
            || !is_array($data->operations) || !array_is_list($data->operations)) {
            throw new PatchValidationException('invalid_envelope');
        }
        if (count($data->operations) > $this->options->maxOperations) {
            throw new PatchLimitException('patch_limit');
        }
        if ($data->unsupported) {
            throw new PatchValidationException($data->operations === [] ? 'unsupported_request' : 'invalid_envelope');
        }
        $operations = [];
        foreach ($data->operations as $index => $operation) {
            if (!$operation instanceof \stdClass || count(get_object_vars($operation)) !== 4
                || !isset($operation->op, $operation->path) || !property_exists($operation, 'value_json') || !property_exists($operation, 'from')
                || !is_string($operation->op) || !is_string($operation->path)
                || !in_array($operation->op, $this->options->allowedOperations, true)
                || ($operation->from !== null && !is_string($operation->from))) {
                throw new PatchValidationException('invalid_operation_fields', $index);
            }
            $native = ['op' => $operation->op, 'path' => $operation->path, 'from' => $operation->from];
            if (in_array($operation->op, ['add', 'replace', 'test'], true)) {
                if (!is_string($operation->value_json)) {
                    throw new PatchValidationException('missing_value', $index, $operation->op, $operation->path);
                }
                try {
                    $native['value'] = JsonValue::decode($operation->value_json);
                } catch (PatchValidationException $exception) {
                    throw new PatchValidationException($exception->errorCode, $index, $operation->op, $operation->path);
                }
            } elseif ($operation->value_json !== null) {
                throw new PatchValidationException('invalid_operation_fields', $index, $operation->op, $operation->path);
            }
            $operations[] = $native;
        }
        $patch = JsonPatch::fromArray($operations);
        if (strlen(JsonValue::encode($patch)) > $this->options->maxPatchBytes) {
            throw new PatchLimitException('patch_limit');
        }
        return $patch;
    }
}
