<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client;

/**
 * Value object for an OpenAI Responses API request.
 *
 * The endpoint is intentionally not configurable; OpenAiClient always sends
 * this payload to POST /v1/responses.
 */
final readonly class AiRequest
{
    /**
     * @param string|array<mixed> $input
     * @param array<string, mixed>|null $text
     * @param array<string, mixed>|null $metadata
     * @param array<int, array<string, mixed>>|null $tools
     * @param string|array<string, mixed>|null $toolChoice
     * @param array<string, mixed> $extraBody Additional Responses API fields.
     */
    public function __construct(
        public string $model,
        public string|array $input,
        public ?string $instructions = null,
        public ?int $maxOutputTokens = null,
        public ?float $temperature = null,
        public ?array $text = null,
        public ?array $metadata = null,
        public ?string $previousResponseId = null,
        public ?array $tools = null,
        public string|array|null $toolChoice = null,
        public ?bool $parallelToolCalls = null,
        public ?bool $stream = null,
        public array $extraBody = [],
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
        $this->addIfNotNull($body, 'temperature', $this->temperature);
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
        return new self(
            model: $this->model,
            input: $this->input,
            instructions: $instructions,
            maxOutputTokens: $this->maxOutputTokens,
            temperature: $this->temperature,
            text: $this->text,
            metadata: $this->metadata,
            previousResponseId: $this->previousResponseId,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
            parallelToolCalls: $this->parallelToolCalls,
            stream: $this->stream,
            extraBody: $this->extraBody,
        );
    }

    public function withExtraBody(array $extraBody): self
    {
        return new self(
            model: $this->model,
            input: $this->input,
            instructions: $this->instructions,
            maxOutputTokens: $this->maxOutputTokens,
            temperature: $this->temperature,
            text: $this->text,
            metadata: $this->metadata,
            previousResponseId: $this->previousResponseId,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
            parallelToolCalls: $this->parallelToolCalls,
            stream: $this->stream,
            extraBody: array_replace_recursive($this->extraBody, $extraBody),
        );
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
