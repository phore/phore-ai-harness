<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

use Phore\JsonPatch\JsonPointer;
use Phore\JsonPatch\JsonValue;
use Phore\JsonPatch\PatchLimitException;
use Phore\JsonPatch\PatchValidationException;

/** Validates the JSON Schema vocabulary emitted by phore/schema, before hydration. */
final class StructSchemaValidator
{
    /** Expand phore/schema class references into JSON Schema definitions, including recursive classes. */
    public static function forClass(string $className): array
    {
        $definitions = [];
        $expand = function (array $schema) use (&$expand, &$definitions): array {
            if (isset($schema['phpClass'])) {
                $class = $schema['phpClass'];
                if (!is_string($class) || !class_exists($class)) {
                    throw new PatchValidationException('unsupported_schema_reference');
                }
                $key = 'class_' . hash('sha256', $class);
                if (!array_key_exists($key, $definitions)) {
                    $definitions[$key] = []; // Break schema cycles, not document validation.
                    $definitions[$key] = $expand((new \Phore\Schema\Parser\SchemaParser())->parseClass($class)
                        ->toJsonSchema(new \Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions(mergeSubTypes: true))->toArray());
                }
                unset($schema['phpClass']);
                $schema['$ref'] = '#/$defs/' . $key;
            }
            foreach ($schema['properties'] ?? [] as $key => $child) {
                $schema['properties'][$key] = $expand($child);
            }
            foreach (['items', 'additionalProperties'] as $key) {
                if (is_array($schema[$key] ?? null)) {
                    $schema[$key] = $expand($schema[$key]);
                }
            }
            foreach (['anyOf', 'allOf'] as $key) {
                foreach ($schema[$key] ?? [] as $index => $child) {
                    $schema[$key][$index] = $expand($child);
                }
            }
            return $schema;
        };
        $schema = $expand((new \Phore\Schema\Parser\SchemaParser())->parseClass($className)
            ->toJsonSchema(new \Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions(mergeSubTypes: true))->toArray());
        if ($definitions !== []) {
            $schema['$defs'] = $definitions;
        }
        return $schema;
    }

    public function assertValid(mixed $value, array $schema, ?array $root = null, int $depth = 0): void
    {
        $root ??= $schema;
        if ($depth > 128) {
            throw new PatchLimitException('schema_depth_limit');
        }
        $known = ['$schema', '$defs', '$ref', 'title', 'description', 'default', 'type', 'properties', 'required', 'additionalProperties', 'items', 'anyOf', 'allOf', 'const', 'enum', 'pattern'];
        foreach ($schema as $key => $_) {
            if (!in_array($key, $known, true)) {
                // Unknown validation vocabulary must never silently pass.
                throw new PatchValidationException('unsupported_schema_keyword');
            }
        }
        if (isset($schema['$ref'])) {
            if (!str_starts_with($schema['$ref'], '#/')) {
                throw new PatchValidationException('unsupported_schema_reference');
            }
            $referenced = $root;
            foreach (JsonPointer::tokens(substr($schema['$ref'], 1)) as $token) {
                if (!is_array($referenced) || !array_key_exists($token, $referenced)) {
                    throw new PatchValidationException('invalid_schema_reference');
                }
                $referenced = $referenced[$token];
            }
            $this->assertValid($value, $referenced, $root, $depth + 1);
        }
        if (isset($schema['anyOf'])) {
            $matched = false;
            foreach ($schema['anyOf'] as $alternative) {
                try {
                    $this->assertValid($value, $alternative, $root, $depth + 1);
                    $matched = true;
                    break;
                } catch (PatchValidationException $exception) {
                    if ($exception->errorCode !== 'schema_validation_failed') {
                        throw $exception;
                    }
                }
            }
            if (!$matched) {
                $this->fail();
            }
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            $this->assertValid($value, $part, $root, $depth + 1);
        }
        if (isset($schema['type'])) {
            $matched = false;
            foreach ((array) $schema['type'] as $type) {
                $matched = $matched || match ($type) {
                    'object' => $value instanceof \stdClass,
                    'array' => is_array($value) && array_is_list($value),
                    'string' => is_string($value),
                    'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
                    'number' => is_int($value) || is_float($value),
                    'boolean' => is_bool($value),
                    'null' => $value === null,
                    default => throw new PatchValidationException('unsupported_schema_type'),
                };
            }
            if (!$matched) {
                $this->fail();
            }
        }
        if (array_key_exists('const', $schema) && !JsonValue::equal($value, JsonValue::copy($schema['const']))) {
            $this->fail();
        }
        if (isset($schema['enum'])) {
            $matched = false;
            foreach ($schema['enum'] as $candidate) {
                $matched = $matched || JsonValue::equal($value, JsonValue::copy($candidate));
            }
            if (!$matched) {
                $this->fail();
            }
        }
        if (is_string($value) && isset($schema['pattern']) && preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~u', $value) !== 1) {
            $this->fail();
        }
        if ($value instanceof \stdClass) {
            foreach ($schema['required'] ?? [] as $name) {
                if (!property_exists($value, $name)) {
                    $this->fail();
                }
            }
            foreach ($value as $name => $child) {
                $properties = (array) ($schema['properties'] ?? []);
                if (array_key_exists($name, $properties)) {
                    $this->assertValid($child, $properties[$name], $root, $depth + 1);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $this->fail();
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->assertValid($child, $schema['additionalProperties'], $root, $depth + 1);
                }
            }
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $child) {
                $this->assertValid($child, $schema['items'], $root, $depth + 1);
            }
        }
    }

    private function fail(): never
    {
        throw new PatchValidationException('schema_validation_failed');
    }
}
