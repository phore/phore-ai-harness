<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\OpenAI\OpenAiPromptToContentConverter;
use Phore\AiHarness\Client\OpenAI\OpenAiPromptTypeConverter;
use Phore\AiHarness\PromptType\AudioPrompt;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use PHPUnit\Framework\TestCase;

final readonly class OpenAiPromptTypeConverterTestAddress
{
    public function __construct(
        public string $city,
        public int $zip,
    ) {
    }
}

/**
 * Looks up a weather forecast.
 *
 * @param string $city City name to look up.
 * @param int $days Number of forecast days.
 * @return string Short weather summary.
 */
function openAiPromptTypeConverterTestLookupWeather(string $city, int $days = 1): string
{
    return $city . ':' . $days;
}

final class OpenAiPromptTypeConverterTest extends TestCase
{
    public function testConvertsSingleTextPromptToInputTextContentSection(): void
    {
        $payload = (new OpenAiPromptTypeConverter())->convert(new TextPrompt('Hello'));

        self::assertSame([
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Hello',
                ]],
            ]],
        ], $payload);
    }

    public function testConvertsSystemPromptToInstructions(): void
    {
        $payload = (new OpenAiPromptTypeConverter())->convert([
            new SystemPrompt('Answer in German.'),
            new TextPrompt('Hello'),
        ]);

        self::assertSame([[// user message
            'role' => 'user',
            'content' => [[
                'type' => 'input_text',
                'text' => 'Hello',
            ]],
        ]], $payload['input']);
        self::assertSame('Answer in German.', $payload['instructions']);
    }

    public function testConvertsMultipleUserPromptsToContentSections(): void
    {
        $payload = (new OpenAiPromptTypeConverter())->convert([
            new TextPrompt('Analysiere die folgenden Dateien.'),
            new FilePrompt('styleguide.md', 'File content', 'text/markdown'),
            new ImagePrompt('data:image/png;base64,abc', 'image.png', 'image/png'),
            new AudioPrompt('base64-audio', 'mp3'),
        ]);

        self::assertSame('user', $payload['input'][0]['role']);
        self::assertSame([
            [
                'type' => 'input_text',
                'text' => 'Analysiere die folgenden Dateien.',
            ],
            [
                'type' => 'input_file',
                'filename' => 'styleguide.md',
                'file_data' => 'data:text/markdown;base64,' . base64_encode('File content'),
            ],
            [
                'type' => 'input_image',
                'image_url' => 'data:image/png;base64,abc',
            ],
            [
                'type' => 'input_audio',
                'format' => 'mp3',
                'data' => 'base64-audio',
            ],
        ], $payload['input'][0]['content']);
    }

    public function testContentConverterBuildsSingleContentSections(): void
    {
        $sections = (new OpenAiPromptToContentConverter())->convert([
            new TextPrompt('Hello'),
            new ImagePrompt('data:image/png;base64,abc'),
        ]);

        self::assertSame([
            ['type' => 'input_text', 'text' => 'Hello'],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,abc'],
        ], $sections);
    }

    public function testConvertsStructPromptWithObjectToJsonSchemaAndDataText(): void
    {
        $text = (new OpenAiPromptTypeConverter())->convertPromptToText(
            new StructPrompt(new OpenAiPromptTypeConverterTestAddress('Berlin', 10115)),
        );

        self::assertStringContainsString('JSON Schema:', $text);
        self::assertStringContainsString('Data:', $text);
        self::assertStringContainsString('"city": "Berlin"', $text);
        self::assertStringContainsString('"zip": 10115', $text);
    }

    public function testConvertsStructPromptWithClassNameToJsonSchemaOnlyText(): void
    {
        $text = (new OpenAiPromptTypeConverter())->convertPromptToText(
            new StructPrompt(OpenAiPromptTypeConverterTestAddress::class),
        );

        self::assertStringContainsString('JSON Schema:', $text);
        self::assertStringNotContainsString('Data:', $text);
    }

    public function testConvertsStructPromptWithArrayToDataOnlyText(): void
    {
        $text = (new OpenAiPromptTypeConverter())->convertPromptToText(
            new StructPrompt(['city' => 'Berlin', 'zip' => 10115]),
        );

        self::assertStringContainsString('Structured data', $text);
        self::assertStringNotContainsString('Structured PHP type:', $text);
        self::assertStringNotContainsString('JSON Schema:', $text);
        self::assertStringContainsString('Data:', $text);
        self::assertStringContainsString('"city": "Berlin"', $text);
    }

    public function testConvertsStructPromptAliasToReferenceText(): void
    {
        $text = (new OpenAiPromptTypeConverter())->convertPromptToText(
            new StructPrompt(OpenAiPromptTypeConverterTestAddress::class, alias: 'billingAddress'),
        );

        self::assertStringContainsString('Reference alias: billingAddress', $text);
        self::assertStringContainsString('Other prompts may refer to this struct as `billingAddress`.', $text);
    }

    public function testConvertsStructPromptInstructionsToText(): void
    {
        $text = (new OpenAiPromptTypeConverter())->convertPromptToText(
            new StructPrompt(
                OpenAiPromptTypeConverterTestAddress::class,
                instructions: 'Use this as the normalized billing address.',
            ),
        );

        self::assertStringContainsString('Struct instructions:', $text);
        self::assertStringContainsString('Use this as the normalized billing address.', $text);
    }

    public function testCreatesAiRequest(): void
    {
        $request = (new OpenAiPromptTypeConverter())->toAiRequest('gpt-5-mini', [
            new SystemPrompt('Be short.'),
            new TextPrompt('Hello'),
        ]);

        self::assertInstanceOf(AiRequest::class, $request);
        self::assertSame([
            'model' => 'gpt-5-mini',
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Hello',
                ]],
            ]],
            'instructions' => 'Be short.',
        ], $request->toArray());
    }

    public function testConvertsCallbackToolToOpenAiFunctionTool(): void
    {
        $tool = (new OpenAiPromptTypeConverter())->convertCallbackTool(new CallbackTool(
            'openAiPromptTypeConverterTestLookupWeather',
            name: 'lookup_weather',
        ));

        self::assertSame('function', $tool['type']);
        self::assertSame('lookup_weather', $tool['name']);
        self::assertStringContainsString('Looks up a weather forecast.', $tool['description']);
        self::assertStringContainsString('Returns JSON matching this schema:', $tool['description']);
        self::assertTrue($tool['strict']);
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'city' => [
                    'type' => 'string',
                    'description' => 'City name to look up.',
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Number of forecast days.',
                ],
            ],
            'additionalProperties' => false,
            'required' => ['city', 'days'],
        ], $tool['parameters']);
    }
}
