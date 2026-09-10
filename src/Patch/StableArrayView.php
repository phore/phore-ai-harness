<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

/** RFC patches operate on this transport view, never on a custom pointer dialect. */
final class StableArrayView
{
    private int $sequence = 0;

    /** @param array<string, string> $identityPointers Original array pointer => identity pointer within each element. */
    public function __construct(private readonly array $identityPointers = [])
    {
        foreach ($identityPointers as $path => $identity) {
            JsonPointer::tokens((string) $path);
            JsonPointer::tokens($identity);
        }
    }

    public function encode(mixed $document): mixed
    {
        $this->sequence = 0;
        return $this->encodeValue(JsonValue::copy($document), '');
    }

    private function encodeValue(mixed $value, string $path): mixed
    {
        if (is_array($value)) {
            $order = [];
            $values = new \stdClass();
            foreach ($value as $index => $item) {
                $id = $this->identity($item, $path);
                if ($id === null || property_exists($values, $id)) {
                    if ($id !== null && isset($this->identityPointers[$path])) {
                        throw new PatchValidationException('duplicate_identity');
                    }
                    do {
                        $id = 'e_' . ++$this->sequence;
                    } while (property_exists($values, $id));
                }
                $order[] = $id;
                $values->{$id} = $this->encodeValue($item, $path . '/' . $index);
            }
            return (object) ['$order' => $order, '$values' => $values];
        }
        if ($value instanceof \stdClass) {
            if (property_exists($value, '$order') || property_exists($value, '$values')) {
                throw new PatchValidationException('reserved_stable_property');
            }
            foreach ($value as $key => $child) {
                $value->{$key} = $this->encodeValue($child, $path . '/' . JsonPointer::escape((string) $key));
            }
        }
        return $value;
    }

    private function identity(mixed $item, string $path): ?string
    {
        if (isset($this->identityPointers[$path])) {
            $identity = JsonPointer::get($item, $this->identityPointers[$path]);
            if (!is_scalar($identity)) {
                throw new PatchValidationException('invalid_identity');
            }
            return 'i_' . substr(hash('sha256', JsonValue::encode($identity)), 0, 24);
        }
        if ($item instanceof \stdClass) {
            foreach (['id', 'key', 'uuid'] as $key) {
                if (property_exists($item, $key) && is_scalar($item->{$key})) {
                    return 'i_' . substr(hash('sha256', $key . ':' . JsonValue::encode($item->{$key})), 0, 24);
                }
            }
        }
        return null;
    }

    public function decode(mixed $view): mixed
    {
        return $this->decodeValue(JsonValue::copy($view));
    }

    private function decodeValue(mixed $value): mixed
    {
        // Every list in the transport must be wrapped, except its own $order.
        if (is_array($value)) {
            throw new PatchValidationException('unwrapped_stable_array');
        }
        if (!$value instanceof \stdClass) {
            return $value;
        }
        if (property_exists($value, '$order') || property_exists($value, '$values')) {
            if (count(get_object_vars($value)) !== 2 || !isset($value->{'$order'}, $value->{'$values'})
                || !is_array($value->{'$order'}) || !array_is_list($value->{'$order'}) || !$value->{'$values'} instanceof \stdClass) {
                throw new PatchValidationException('invalid_stable_view');
            }
            $seen = [];
            $result = [];
            foreach ($value->{'$order'} as $id) {
                if (!is_string($id) || $id === '' || isset($seen[$id]) || !property_exists($value->{'$values'}, $id)) {
                    throw new PatchValidationException('invalid_stable_order');
                }
                $seen[$id] = true;
                $result[] = $this->decodeValue($value->{'$values'}->{$id});
            }
            if (count($seen) !== count(get_object_vars($value->{'$values'}))) {
                throw new PatchValidationException('orphan_stable_value');
            }
            return $result;
        }
        foreach ($value as $key => $child) {
            $value->{$key} = $this->decodeValue($child);
        }
        return $value;
    }
}
