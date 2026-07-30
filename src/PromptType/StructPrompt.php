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
    private string $className;

    /**
     * @var array<string, mixed>
     */
    private array $jsonSchema;

    private bool $hasData;

    private mixed $data;

    /**
     * @param class-string|object $classOrObject
     */
    public function __construct(
        public string|object $classOrObject,
        ?JsonSchemaGeneratorOptions $jsonSchemaOptions = null,
    ) {
        $this->className = $this->resolveClassName($classOrObject);
        $this->jsonSchema = (new SchemaParser())
            ->parseClass($classOrObject)
            ->toJsonSchema($jsonSchemaOptions)
            ->toArray();
        $this->hasData = is_object($classOrObject);
        $this->data = $this->hasData ? $this->normalizeObject($classOrObject) : null;
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

    public function hasData(): bool
    {
        return $this->hasData;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    /**
     * @return array{type: string, className: string, jsonSchema: array<string, mixed>, data?: mixed}
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type(),
            'className' => $this->className,
            'jsonSchema' => $this->jsonSchema,
        ];

        if ($this->hasData) {
            $array['data'] = $this->data;
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
            throw new InvalidArgumentException('StructPrompt expects an object or an existing class name. Got: ' . $classOrObject);
        }

        /** @var class-string $classOrObject */
        return (new ReflectionClass($classOrObject))->getName();
    }

    private function normalizeObject(object $object): mixed
    {
        return json_decode(Toolkit::jsonEncode($object, true), true, 512, JSON_THROW_ON_ERROR);
    }
}
