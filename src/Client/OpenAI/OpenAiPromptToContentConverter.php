<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client\OpenAI;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\Helper\DataUrl;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\AudioPrompt;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;

final readonly class OpenAiPromptToContentConverter
{
    /**
     * @param PromptType|iterable<PromptType> $prompts
     * @return list<array<string, mixed>>
     * @throws JsonException
     */
    public function convert(PromptType|iterable $prompts): array
    {
        $sections = [];
        foreach ($this->normalizePrompts($prompts) as $prompt) {
            $sections[] = $this->convertPrompt($prompt);
        }

        return $sections;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function convertPrompt(PromptType $prompt): array
    {
        return match (true) {
            $prompt instanceof TextPrompt => [
                'type' => 'input_text',
                'text' => $prompt->text,
            ],
            $prompt instanceof SystemPrompt => [
                'type' => 'input_text',
                'text' => $prompt->text,
            ],
            $prompt instanceof FilePrompt => [
                'type' => 'input_file',
                'filename' => $prompt->fileName,
                'file_data' => (new DataUrl($prompt->content, $prompt->contentType))->toString(),
            ],
            $prompt instanceof ImagePrompt => [
                'type' => 'input_image',
                'image_url' => $prompt->imageUrl,
            ],
            $prompt instanceof AudioPrompt => [
                'type' => 'input_audio',
                'format' => $prompt->format,
                'data' => $prompt->data,
            ],
            $prompt instanceof StructPrompt => [
                'type' => 'input_text',
                'text' => $this->convertStructPrompt($prompt),
            ],
            default => throw new InvalidArgumentException('Unsupported PromptType: ' . $prompt::class),
        };
    }

    /**
     * @throws JsonException
     */
    public function convertPromptToText(PromptType $prompt): string
    {
        return match (true) {
            $prompt instanceof TextPrompt => $prompt->text,
            $prompt instanceof FilePrompt => "File: {$prompt->fileName}\n```\n{$prompt->content}\n```",
            $prompt instanceof ImagePrompt => ($prompt->fileName !== null ? 'Image: ' . $prompt->fileName : 'Image') . "\n" . $prompt->imageUrl,
            $prompt instanceof AudioPrompt => ($prompt->fileName !== null ? 'Audio: ' . $prompt->fileName : 'Audio') . "\n" . $prompt->format,
            $prompt instanceof StructPrompt => $this->convertStructPrompt($prompt),
            $prompt instanceof SystemPrompt => $prompt->text,
            default => throw new InvalidArgumentException('Unsupported PromptType: ' . $prompt::class),
        };
    }

    /**
     * @throws JsonException
     */
    private function convertStructPrompt(StructPrompt $prompt): string
    {
        $text = $prompt->className() !== null
            ? "Structured PHP type: {$prompt->className()}\n"
            : "Structured data\n";

        if ($prompt->alias() !== null) {
            $text .= "Reference alias: {$prompt->alias()}\n"
                . "Other prompts may refer to this struct as `{$prompt->alias()}`.\n";
        }

        if ($prompt->instructions() !== null) {
            $text .= "Struct instructions:\n{$prompt->instructions()}\n";
        }

        if ($prompt->jsonSchema() !== null) {
            $text .= "JSON Schema:\n```json\n"
                . Toolkit::jsonEncode($prompt->jsonSchema(), true)
                . "\n```";
        }

        if ($prompt->hasData()) {
            $text .= "\nData:\n```json\n" . Toolkit::jsonEncode($prompt->data(), true) . "\n```";
        }

        return $text;
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
