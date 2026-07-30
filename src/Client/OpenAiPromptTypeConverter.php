<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;

/**
 * Converts generic PromptType value objects into OpenAI Responses API fields.
 */
final readonly class OpenAiPromptTypeConverter
{
    /**
     * Converts one or more PromptTypes to AiRequest constructor fields.
     *
     * SystemPrompt instances are collected into the OpenAI `instructions` field.
     * All other prompts become user input.
     *
     * @param PromptType|iterable<PromptType> $prompts
     * @return array{input: string|array<int, array{role: string, content: string|array<int, array<string, mixed>>}>, instructions?: string}
     * @throws JsonException
     */
    public function convert(PromptType|iterable $prompts): array
    {
        $instructions = [];
        $messages = [];

        foreach ($this->normalizePrompts($prompts) as $prompt) {
            if ($prompt instanceof SystemPrompt) {
                $instructions[] = $prompt->text;
                continue;
            }

            $messages[] = [
                'role' => 'user',
                'content' => $this->convertPromptToContent($prompt),
            ];
        }

        $payload = [
            'input' => $this->messagesToInput($messages),
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
     * Renders a non-system PromptType as OpenAI user input text.
     *
     * @throws JsonException
     */
    public function convertPromptToText(PromptType $prompt): string
    {
        return match (true) {
            $prompt instanceof TextPrompt => $prompt->text,
            $prompt instanceof FilePrompt => $this->convertFilePrompt($prompt),
            $prompt instanceof ImagePrompt => $this->convertImagePromptToText($prompt),
            $prompt instanceof StructPrompt => $this->convertStructPrompt($prompt),
            $prompt instanceof SystemPrompt => $prompt->text,
            default => throw new InvalidArgumentException('Unsupported PromptType: ' . $prompt::class),
        };
    }

    private function convertFilePrompt(FilePrompt $prompt): string
    {
        return "File: {$prompt->fileName}\n```\n{$prompt->content}\n```";
    }

    /**
     * @return string|array<int, array<string, mixed>>
     * @throws JsonException
     */
    private function convertPromptToContent(PromptType $prompt): string|array
    {
        if ($prompt instanceof ImagePrompt) {
            return [[
                'type' => 'input_image',
                'image_url' => $prompt->imageUrl,
            ]];
        }

        return $this->convertPromptToText($prompt);
    }

    private function convertImagePromptToText(ImagePrompt $prompt): string
    {
        $label = $prompt->fileName !== null ? 'Image: ' . $prompt->fileName : 'Image';

        return $label . "\n" . $prompt->imageUrl;
    }

    /**
     * @throws JsonException
     */
    private function convertStructPrompt(StructPrompt $prompt): string
    {
        $text = "Structured PHP type: {$prompt->className()}\n"
            . "JSON Schema:\n```json\n"
            . Toolkit::jsonEncode($prompt->jsonSchema(), true)
            . "\n```";

        if ($prompt->hasData()) {
            $text .= "\nData:\n```json\n" . Toolkit::jsonEncode($prompt->data(), true) . "\n```";
        }

        return $text;
    }

    /**
     * @param list<array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     * @return string|array<int, array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    private function messagesToInput(array $messages): string|array
    {
        if ($messages === []) {
            return '';
        }

        if (count($messages) === 1 && is_string($messages[0]['content'])) {
            return $messages[0]['content'];
        }

        return $messages;
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
