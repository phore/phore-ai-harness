<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

final readonly class JsonPatch implements \JsonSerializable
{
    /** @param list<JsonPatchOperation> $operations */
    public function __construct(public array $operations)
    {
        if (!array_is_list($operations)) {
            throw new PatchValidationException('invalid_patch');
        }
        foreach ($operations as $operation) {
            if (!$operation instanceof JsonPatchOperation) {
                throw new PatchValidationException('invalid_patch');
            }
        }
    }

    public static function fromArray(array $operations): self
    {
        if (!array_is_list($operations)) {
            throw new PatchValidationException('invalid_patch');
        }
        $result = [];
        foreach ($operations as $index => $operation) {
            if ($operation instanceof \stdClass) {
                $operation = get_object_vars($operation);
            }
            if (!is_array($operation)) {
                throw new PatchValidationException('invalid_operation', $index);
            }
            try {
                $result[] = JsonPatchOperation::fromArray($operation);
            } catch (PatchValidationException $exception) {
                throw new PatchValidationException($exception->errorCode, $index,
                    is_string($operation['op'] ?? null) ? $operation['op'] : null,
                    is_string($operation['path'] ?? null) ? $operation['path'] : null);
            }
        }
        return new self($result);
    }

    public function jsonSerialize(): array
    {
        return $this->operations;
    }
}
