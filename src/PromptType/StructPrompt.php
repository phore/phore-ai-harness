<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use InvalidArgumentException;
use Phore\AiHarness\Helper\Toolkit;
use Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions;
use Phore\Schema\Parser\SchemaParser;
use ReflectionClass;

final readonly class StructPrompt implements PromptType
{
    private ?string $className;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $jsonSchema;

    private bool $hasData;

    private mixed $data;

    private ?string $alias;

    private ?string $instructions;

    /**
     * @param class-string|object|array<string|int, mixed> $classOrObject
     */
    public function __construct(
        public string|object|array $classOrObject,
        ?string $alias = null,
        ?string $instructions = null,
        ?JsonSchemaGeneratorOptions $jsonSchemaOptions = null,
    ) {
        $this->alias = $this->validateAlias($alias);
        $this->instructions = $this->validateInstructions($instructions);

        if (is_array($classOrObject)) {
            $this->className = null;
            $this->jsonSchema = null;
            $this->hasData = true;
            $this->data = $this->normalizeData($classOrObject);
            return;
        }

        $this->className = $this->resolveClassName($classOrObject);
        $this->jsonSchema = (new SchemaParser())
            ->parseClass($classOrObject)
            ->toJsonSchema($jsonSchemaOptions)
            ->toArray();
        $this->hasData = is_object($classOrObject);
        $this->data = $this->hasData ? $this->normalizeData($classOrObject) : null;
    }

    public function type(): string
    {
        return 'struct';
    }

    public function className(): ?string
    {
        return $this->className;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jsonSchema(): ?array
    {
        return $this->jsonSchema;
    }

    public function hasData(): bool
    {
        return $this->hasData;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    public function alias(): ?string
    {
        return $this->alias;
    }

    public function instructions(): ?string
    {
        return $this->instructions;
    }

    /**
     * @return array{type: string, className?: string, jsonSchema?: array<string, mixed>, alias?: string, instructions?: string, data?: mixed}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
        ];

        if ($this->className !== null) {
            $array['className'] = $this->className;
        }

        if ($this->jsonSchema !== null) {
            $array['jsonSchema'] = $this->jsonSchema;
        }

        if ($this->alias !== null) {
            $array['alias'] = $this->alias;
        }

        if ($this->instructions !== null) {
            $array['instructions'] = $this->instructions;
        }

        if ($this->hasData) {
            $array['data'] = $this->data;
        }

        return $array;
    }

    private function validateAlias(?string $alias): ?string
    {
        if ($alias === null) {
            return null;
        }

        if (trim($alias) === '') {
            throw new InvalidArgumentException('StructPrompt alias must not be empty.');
        }

        return $alias;
    }

    private function validateInstructions(?string $instructions): ?string
    {
        if ($instructions === null) {
            return null;
        }

        if (trim($instructions) === '') {
            throw new InvalidArgumentException('StructPrompt instructions must not be empty.');
        }

        return $instructions;
    }

    /**
     * @param class-string|object $classOrObject
     */
    private function resolveClassName(string|object $classOrObject): string
    {
        if (is_object($classOrObject)) {
            return $classOrObject::class;
        }

        if (!class_exists($classOrObject)) {
            throw new InvalidArgumentException('StructPrompt expects an object or an existing class name. Got: ' . $classOrObject);
        }

        /** @var class-string $classOrObject */
        return (new ReflectionClass($classOrObject))->getName();
    }

    private function normalizeData(object|array $data): mixed
    {
        return json_decode(Toolkit::jsonEncode($data, true), true, 512, JSON_THROW_ON_ERROR);
    }
}
