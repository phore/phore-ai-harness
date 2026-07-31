<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client\OpenAI;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\ToolType;
use Phore\Schema\Generator\JsonSchema\JsonClassSchemaGenerator;
use Phore\Schema\Generator\JsonSchema\JsonSchemaCompatibility;
use Phore\Schema\Generator\JsonSchema\JsonSchemaGeneratorOptions;
use Phore\Schema\Parser\SchemaParser;
use Phore\Schema\Schema\FunctionParameterSchema;
use Phore\Schema\Schema\FunctionReturnSchema;
use Phore\Schema\Schema\Type\SchemaType;

/**
 * Converts generic PromptType value objects into OpenAI Responses API fields.
 */
final readonly class OpenAiPromptTypeConverter
{
    public function __construct(
        private OpenAiPromptToContentConverter $contentConverter = new OpenAiPromptToContentConverter(),
    ) {
    }

    /**
     * Converts one or more PromptTypes to AiRequest constructor fields.
     *
     * SystemPrompt instances are collected into the OpenAI `instructions` field.
     * All other prompts become one user message with OpenAI content sections.
     *
     * @param PromptType|iterable<PromptType> $prompts
     * @return array{input: string|array<int, array{role: string, content: array<int, array<string, mixed>>}>, instructions?: string}
     * @throws JsonException
     */
    public function convert(PromptType|iterable $prompts): array
    {
        $instructions = [];
        $contentPrompts = [];

        foreach ($this->normalizePrompts($prompts) as $prompt) {
            if ($prompt instanceof SystemPrompt) {
                $instructions[] = $prompt->text;
                continue;
            }

            $contentPrompts[] = $prompt;
        }

        $content = $this->contentConverter->convert($contentPrompts);
        $payload = [
            'input' => $content === [] ? '' : [[
                'role' => 'user',
                'content' => $content,
            ]],
        ];

        if ($instructions !== []) {
            $payload['instructions'] = implode("\n\n", $instructions);
        }

        return $payload;
    }

    /**
     * Creates an AiRequest from PromptTypes.
     *
     * @param PromptType|iterable<PromptType> $prompts
     * @throws JsonException
     */
    public function toAiRequest(string $model, PromptType|iterable $prompts): AiRequest
    {
        $payload = $this->convert($prompts);

        return new AiRequest(
            model: $model,
            input: $payload['input'],
            instructions: $payload['instructions'] ?? null,
        );
    }

    /**
     * Renders a non-system PromptType as plain text for compatibility.
     *
     * @throws JsonException
     */
    public function convertPromptToText(PromptType $prompt): string
    {
        return $this->contentConverter->convertPromptToText($prompt);
    }

    /**
     * Converts ToolType instances to OpenAI Responses API tool definitions.
     *
     * @return array<string, mixed>
     */
    public function convertTool(ToolType $tool): array
    {
        if ($tool instanceof CallbackTool) {
            return $this->convertCallbackTool($tool);
        }

        return $tool->toArray('open_ai');
    }

    /**
     * Converts a PHP callback into an OpenAI user function tool definition.
     *
     * phore/schema parses the callable signature and PHPDoc. Parameter schemas become the
     * OpenAI function `parameters` JSON Schema. The parsed return schema is added to the
     * function description because OpenAI function tools do not have a dedicated return
     * schema field.
     *
     * @return array<string, mixed>
     */
    public function convertCallbackTool(CallbackTool $tool): array
    {
        $functionSchema = (new SchemaParser())->parseCallable($tool->callback());
        $description = $tool->description ?? $functionSchema->description;
        $description = trim($description);
        if ($description === '') {
            $description = 'PHP callback function.';
        }

        $returnDescription = $this->formatReturnDescription($functionSchema->return);
        if ($returnDescription !== '') {
            $description .= "\n\n" . $returnDescription;
        }

        return [
            'type' => 'function',
            'name' => $tool->name(),
            'description' => $description,
            'parameters' => $this->functionParametersToJsonSchema($functionSchema->parameters, $tool->strict),
            'strict' => $tool->strict,
        ];
    }

    /**
     * @param list<FunctionParameterSchema> $parameters
     * @return array<string, mixed>
     */
    private function functionParametersToJsonSchema(array $parameters, bool $strict): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            $property = $this->schemaTypeToJsonSchema($parameter->type);
            if ($parameter->description !== '') {
                $property['description'] = $parameter->description;
            }

            $properties[$parameter->name] = $property;

            if ($strict || (!$parameter->hasDefaultValue && !$parameter->allowsNull)) {
                $required[] = $parameter->name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function formatReturnDescription(FunctionReturnSchema $return): string
    {
        if ($return->isVoid) {
            return 'Returns: void.';
        }

        $jsonSchema = $this->schemaTypeToJsonSchema($return->type);
        $description = 'Returns JSON matching this schema: ' . Toolkit::jsonEncode($jsonSchema);

        if ($return->description !== '') {
            $description .= ' ' . $return->description;
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaTypeToJsonSchema(SchemaType $type): array
    {
        return (new JsonClassSchemaGenerator())
            ->generate($type, new JsonSchemaGeneratorOptions(JsonSchemaCompatibility::OpenAiStructuredOutput))
            ->toArray();
    }

    /**
     * @param PromptType|iterable<PromptType> $prompts
     * @return iterable<PromptType>
     */
    private function normalizePrompts(PromptType|iterable $prompts): iterable
    {
        if ($prompts instanceof PromptType) {
            yield $prompts;
            return;
        }

        foreach ($prompts as $prompt) {
            if (!$prompt instanceof PromptType) {
                throw new InvalidArgumentException('Expected only PromptType instances.');
            }

            yield $prompt;
        }
    }
}
