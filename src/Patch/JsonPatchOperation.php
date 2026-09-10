<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

final readonly class JsonPatchOperation implements \JsonSerializable
{
    private mixed $storedValue;

    public function __construct(
        public string $op,
        public string $path,
        mixed $value = null,
        public ?string $from = null,
    ) {
        if (!in_array($op, ['add', 'remove', 'replace', 'move', 'copy', 'test'], true)) {
            throw new PatchValidationException('invalid_operation');
        }
        JsonPointer::tokens($path);
        if (in_array($op, ['add', 'replace', 'test'], true) && func_num_args() < 3) {
            throw new PatchValidationException('missing_value');
        }
        if (in_array($op, ['move', 'copy'], true)) {
            if ($from === null || $value !== null) {
                throw new PatchValidationException('invalid_operation_fields');
            }
            JsonPointer::tokens($from);
        } elseif ($from !== null || ($op === 'remove' && $value !== null)) {
            throw new PatchValidationException('invalid_operation_fields');
        }
        $this->storedValue = JsonValue::copy($value);
    }

    public function value(): mixed
    {
        return JsonValue::copy($this->storedValue);
    }

    public static function fromArray(array $operation): self
    {
        if (!isset($operation['op'], $operation['path']) || !is_string($operation['op']) || !is_string($operation['path'])) {
            throw new PatchValidationException('invalid_operation_fields');
        }
        if (in_array($operation['op'], ['add', 'replace', 'test'], true) && !array_key_exists('value', $operation)) {
            throw new PatchValidationException('missing_value');
        }
        if (isset($operation['from']) && !is_string($operation['from'])) {
            throw new PatchValidationException('invalid_operation_fields');
        }
        // Unrecognized members are ignored as required by RFC 6902.
        return new self($operation['op'], $operation['path'], $operation['value'] ?? null, $operation['from'] ?? null);
    }

    public function jsonSerialize(): array
    {
        $result = ['op' => $this->op, 'path' => $this->path];
        if (in_array($this->op, ['add', 'replace', 'test'], true)) {
            $result['value'] = $this->value();
        }
        if ($this->from !== null) {
            $result['from'] = $this->from;
        }
        return $result;
    }
}
