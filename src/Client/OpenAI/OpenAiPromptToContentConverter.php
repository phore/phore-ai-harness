<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client\OpenAI;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\Helper\DataUrl;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\AudioPrompt;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptFile;
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
        $normalized = iterator_to_array($this->normalizePrompts($prompts), false);
        $this->validateRequiredAliases($normalized);

        $sections = [];
        foreach ($this->expandPrompts($normalized) as $prompt) {
            array_push($sections, ...$this->convertPromptToSections($prompt));
        }

        return $sections;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function convertPrompt(PromptType $prompt): array
    {
        $this->validateRequiredAliases([$prompt]);
        return $this->convertPromptToSections($prompt)[0];
    }

    /**
     * @return list<array<string, mixed>>
     * @throws JsonException
     */
    private function convertPromptToSections(PromptType $prompt): array
    {
        return match (true) {
            $prompt instanceof PromptFile => $this->convert($prompt->segments()),
            $prompt instanceof TextPrompt => [[
                'type' => 'input_text',
                'text' => $this->convertTextPrompt($prompt),
            ]],
            $prompt instanceof SystemPrompt => [[
                'type' => 'input_text',
                'text' => $prompt->text,
            ]],
            $prompt instanceof FilePrompt => [
                ...$this->segmentInstructions($prompt, 'file'),
                [
                    'type' => 'input_file',
                    'filename' => $prompt->fileName,
                    'file_data' => (new DataUrl($prompt->content, $prompt->contentType))->toString(),
                ],
            ],
            $prompt instanceof ImagePrompt => [
                ...$this->segmentInstructions($prompt, 'image'),
                [
                    'type' => 'input_image',
                    'image_url' => $prompt->imageUrl,
                ],
            ],
            $prompt instanceof AudioPrompt => [
                ...$this->segmentInstructions($prompt, 'audio'),
                [
                    'type' => 'input_audio',
                    'format' => $prompt->format,
                    'data' => $prompt->data,
                ],
            ],
            $prompt instanceof StructPrompt => [[
                'type' => 'input_text',
                'text' => $this->convertStructPrompt($prompt),
            ]],
            default => throw new InvalidArgumentException('Unsupported PromptType: ' . $prompt::class),
        };
    }

    /** @throws JsonException */
    public function convertPromptToText(PromptType $prompt): string
    {
        if ($prompt instanceof PromptFile) {
            $this->validateRequiredAliases([$prompt]);
            $parts = [];
            foreach ($this->expandPrompts([$prompt]) as $segment) {
                $parts[] = $this->convertPromptToText($segment);
            }
            return implode("\n\n", $parts);
        }

        return match (true) {
            $prompt instanceof TextPrompt => $this->convertTextPrompt($prompt),
            $prompt instanceof FilePrompt => $this->segmentMetadataText($prompt, 'file') . "File: {$prompt->fileName}\n```\n{$prompt->content}\n```",
            $prompt instanceof ImagePrompt => $this->segmentMetadataText($prompt, 'image') . ($prompt->fileName !== null ? 'Image: ' . $prompt->fileName : 'Image') . "\n" . $prompt->imageUrl,
            $prompt instanceof AudioPrompt => $this->segmentMetadataText($prompt, 'audio') . ($prompt->fileName !== null ? 'Audio: ' . $prompt->fileName : 'Audio') . "\n" . $prompt->format,
            $prompt instanceof StructPrompt => $this->convertStructPrompt($prompt),
            $prompt instanceof SystemPrompt => $prompt->text,
            default => throw new InvalidArgumentException('Unsupported PromptType: ' . $prompt::class),
        };
    }

    private function convertTextPrompt(TextPrompt $prompt): string
    {
        $text = '';
        if ($prompt->alias !== null) {
            $text .= "Reference alias: {$prompt->alias}\nOther prompts may refer to this text as `{$prompt->alias}`.\n";
        }
        if ($prompt->instructions !== null) {
            $text .= "Text instructions:\n{$prompt->instructions}\n";
        }
        if ($prompt->type !== null) {
            return $text . "```{$prompt->type}\n{$prompt->text}\n```";
        }
        return $text . $prompt->text;
    }

    /** @return list<array{type: string, text: string}> */
    private function segmentInstructions(FilePrompt|ImagePrompt|AudioPrompt $prompt, string $segmentType): array
    {
        $text = $this->segmentMetadataText($prompt, $segmentType);
        return $text === '' ? [] : [['type' => 'input_text', 'text' => rtrim($text)]];
    }

    private function segmentMetadataText(FilePrompt|ImagePrompt|AudioPrompt $prompt, string $segmentType): string
    {
        $parts = [];
        if ($prompt->alias !== null) {
            $parts[] = "Reference alias: {$prompt->alias}\nOther prompts may refer to the following {$segmentType} segment as `{$prompt->alias}`.";
        }
        if ($prompt->instructions !== null) {
            $parts[] = "Instructions for the following {$segmentType} segment:\n{$prompt->instructions}";
        }
        if ($prompt->type !== null) {
            $parts[] = "Content type hint for the following {$segmentType} segment: {$prompt->type}.";
        }
        return $parts === [] ? '' : implode("\n", $parts) . "\n";
    }

    /** @throws JsonException */
    private function convertStructPrompt(StructPrompt $prompt): string
    {
        $text = $prompt->className() !== null ? "Structured PHP type: {$prompt->className()}\n" : "Structured data\n";
        if ($prompt->alias() !== null) {
            $text .= "Reference alias: {$prompt->alias()}\nOther prompts may refer to this struct as `{$prompt->alias()}`.\n";
        }
        if ($prompt->instructions() !== null) {
            $text .= "Struct instructions:\n{$prompt->instructions()}\n";
        }
        if ($prompt->jsonSchema() !== null) {
            $text .= "JSON Schema:\n```json\n" . Toolkit::jsonEncode($prompt->jsonSchema(), true) . "\n```";
        }
        if ($prompt->hasData()) {
            $text .= "\nData:\n```json\n" . Toolkit::jsonEncode($prompt->data(), true) . "\n```";
        }
        return $text;
    }

    /** @param list<PromptType> $prompts */
    private function validateRequiredAliases(array $prompts): void
    {
        $aliases = [];
        foreach ($this->expandPrompts($prompts) as $prompt) {
            $array = $prompt->toArray();
            if (isset($array['alias']) && is_string($array['alias'])) {
                $aliases[$array['alias']] = true;
            }
        }

        foreach ($prompts as $prompt) {
            if (!$prompt instanceof PromptFile) {
                continue;
            }
            $missing = array_values(array_filter($prompt->requiredAliases(), static fn (string $alias): bool => !isset($aliases[$alias])));
            if ($missing !== []) {
                throw new InvalidArgumentException('PromptFile ' . $prompt->fileName . ' requires missing aliases: ' . implode(', ', $missing));
            }
        }
    }

    /** @param iterable<PromptType> $prompts @return iterable<PromptType> */
    private function expandPrompts(iterable $prompts): iterable
    {
        foreach ($prompts as $prompt) {
            if ($prompt instanceof PromptFile) {
                yield from $this->expandPrompts($prompt->segments());
                continue;
            }
            yield $prompt;
        }
    }

    /** @param PromptType|iterable<PromptType> $prompts @return iterable<PromptType> */
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
