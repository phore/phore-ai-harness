<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\OpenAiPromptTypeConverter;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\ImagePrompt;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\AiHarness\PromptType\SystemPrompt;
use Phore\AiHarness\PromptType\TextPrompt;
use PHPUnit\Framework\TestCase;

final readonly class OpenAiPromptTypeConverterTestAddress
{
    public function __construct(
        public string $city,
        public int $zip,
    ) {
    }
}

final class OpenAiPromptTypeConverterTest extends TestCase
{
    public function testConvertsSingleTextPromptToStringInput(): void
    {
        $payload = (new OpenAiPromptTypeConverter())->convert(new TextPrompt('Hello'));

        self::assertSame(['input' => 'Hello'], $payload);
    }

    public function testConvertsSystemPromptToInstructions(): void
    {
        $payload = (new OpenAiPromptTypeConverter())->convert([
            new SystemPrompt('Answer in German.'),
            new TextPrompt('Hello'),
        ]);

        self::assertSame('Hello', $payload['input']);
        self::assertSame('Answer in German.', $payload['instructions']);
    }

    public function testConvertsMultipleUserPromptsToMessages(): void
    {
        $payload = (new OpenAiPromptTypeConverter())->convert([
            new TextPrompt('Summarize this file.'),
            new FilePrompt('example.txt', 'File content'),
        ]);

        self::assertSame('Summarize this file.', $payload['input'][0]['content']);
        self::assertStringContainsString('File: example.txt', $payload['input'][1]['content']);
        self::assertStringContainsString('File content', $payload['input'][1]['content']);
    }

    public function testConvertsImagePromptToOpenAiImageContent(): void
    {
        $payload = (new OpenAiPromptTypeConverter())->convert(new ImagePrompt('data:image/png;base64,abc', 'image.png', 'image/png'));

        self::assertSame([
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_image',
                        'image_url' => 'data:image/png;base64,abc',
                    ],
                ],
            ],
        ], $payload['input']);
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

    public function testCreatesAiRequest(): void
    {
        $request = (new OpenAiPromptTypeConverter())->toAiRequest('gpt-5-mini', [
            new SystemPrompt('Be short.'),
            new TextPrompt('Hello'),
        ]);

        self::assertInstanceOf(AiRequest::class, $request);
        self::assertSame([
            'model' => 'gpt-5-mini',
            'input' => 'Hello',
            'instructions' => 'Be short.',
        ], $request->toArray());
    }
}
