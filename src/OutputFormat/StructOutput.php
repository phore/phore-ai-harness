<?php

declare(strict_types=1);

namespace Phore\AiHarness\OutputFormat;

use InvalidArgumentException;
use Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions;
use Phore\Schema\Parser\SchemaParser;
use ReflectionClass;

final readonly class StructOutput implements OutputFormat
{
    private string $className;

    /**
     * @var array<string, mixed>
     */
    private array $jsonSchema;

    /**
     * @param class-string|object $classOrObject
     */
    public function __construct(
        public string|object $classOrObject,
        public ?string $description = null,
        ?JsonSchemaGeneratorOptions $jsonSchemaOptions = null,
    ) {
        $this->className = $this->resolveClassName($classOrObject);
        $this->jsonSchema = (new SchemaParser())
            ->parseClass($classOrObject)
            ->toJsonSchema($jsonSchemaOptions)
            ->toArray();
    }

    public function type(): string
    {
        return 'struct';
    }

    public function className(): string
    {
        return $this->className;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        return $this->jsonSchema;
    }

    /**
     * @return array{type: string, className: string, jsonSchema: array<string, mixed>, description?: string}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'className' => $this->className,
            'jsonSchema' => $this->jsonSchema,
        ];

        if ($this->description !== null) {
            $array['description'] = $this->description;
        }

        return $array;
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
            throw new InvalidArgumentException('StructOutput expects an object or an existing class name. Got: ' . $classOrObject);
        }

        /** @var class-string $classOrObject */
        return (new ReflectionClass($classOrObject))->getName();
    }
}
