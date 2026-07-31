<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client;

use InvalidArgumentException;
use Phore\AiHarness\Client\OpenAI\OpenAiPromptTypeConverter;
use Phore\AiHarness\ToolType\ToolType;

/**
 * Value object for an OpenAI Responses API request.
 *
 * The endpoint is intentionally not configurable; OpenAiClient always sends
 * this payload to POST /v1/responses.
 */
final class AiRequest
{
    public static ?self $last = null;

    /**
     * @param string|array<mixed> $input
     * @param array<string, mixed>|null $text
     * @param array<string, mixed>|null $metadata
     * @param array<int, array<string, mixed>>|null $tools
     * @param string|array<string, mixed>|null $toolChoice
     * @param array<string, mixed> $extraBody Additional Responses API fields.
     */
    public function __construct(
        public readonly string $model,
        public readonly string|array $input,
        public readonly ?string $instructions = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?array $text = null,
        public readonly ?array $metadata = null,
        public readonly ?string $previousResponseId = null,
        public readonly ?array $tools = null,
        public readonly string|array|null $toolChoice = null,
        public readonly ?bool $parallelToolCalls = null,
        public readonly ?bool $stream = null,
        public readonly array $extraBody = [],
    ) {
    }

    public static function text(string $model, string|array $input): self
    {
        return new self(model: $model, input: $input);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?bool $stream = null): array
    {
        $body = [
            'model' => $this->model,
            'input' => $this->input,
        ];

        $this->addIfNotNull($body, 'instructions', $this->instructions);
        $this->addIfNotNull($body, 'max_output_tokens', $this->maxOutputTokens);
        $this->addIfNotNull($body, 'text', $this->text);
        $this->addIfNotNull($body, 'metadata', $this->metadata);
        $this->addIfNotNull($body, 'previous_response_id', $this->previousResponseId);
        $this->addIfNotNull($body, 'tools', $this->tools);
        $this->addIfNotNull($body, 'tool_choice', $this->toolChoice);
        $this->addIfNotNull($body, 'parallel_tool_calls', $this->parallelToolCalls);

        if ($stream !== null) {
            $body['stream'] = $stream;
        } elseif ($this->stream !== null) {
            $body['stream'] = $this->stream;
        }

        return array_replace_recursive($body, $this->extraBody);
    }

    public function withInstructions(string $instructions): self
    {
        return clone($this, [
            'instructions' => $instructions,
        ]);
    }

    /**
     * Configures OpenAI structured output for this request.
     *
     * @param array<string, mixed> $jsonSchema
     */
    public function withOutputSchema(string $name, array $jsonSchema, ?string $description = null, bool $strict = true): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('Output schema name must not be empty.');
        }

        $format = [
            'type' => 'json_schema',
            'name' => $name,
            'schema' => $jsonSchema,
            'strict' => $strict,
        ];

        if ($description !== null) {
            $format['description'] = $description;
        }

        return clone($this, [
            'text' => array_replace_recursive($this->text ?? [], ['format' => $format]),
        ]);
    }

    public function withExtraBody(array $extraBody): self
    {
        return clone($this, [
            'extraBody' => array_replace_recursive($this->extraBody, $extraBody),
        ]);
    }

    public function withTools(ToolType ...$tools): self
    {
        $converter = new OpenAiPromptTypeConverter();

        return clone($this, [
            'tools' => array_map(static fn (ToolType $tool): array => $converter->convertTool($tool), $tools),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addIfNotNull(array &$body, string $key, mixed $value): void
    {
        if ($value !== null) {
            $body[$key] = $value;
        }
    }
}
