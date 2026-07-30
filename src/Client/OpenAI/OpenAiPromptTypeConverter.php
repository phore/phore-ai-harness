<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client\OpenAI;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\SystemPrompt;

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
