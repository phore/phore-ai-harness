<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\ToolType\CodeInterpreterTool;
use Phore\AiHarness\ToolType\WebAccessTool;
use PHPUnit\Framework\TestCase;

final class AiRequestTest extends TestCase
{
    public function testSerializesResponsesApiPayload(): void
    {
        $request = new AiRequest(
            model: 'gpt-5-mini',
            input: 'Hello',
            instructions: 'Answer shortly',
            maxOutputTokens: 123,
            temperature: 0.2,
            metadata: ['tenant' => 'test'],
        );

        self::assertSame([
            'model' => 'gpt-5-mini',
            'input' => 'Hello',
            'instructions' => 'Answer shortly',
            'max_output_tokens' => 123,
            'metadata' => ['tenant' => 'test'],
            'stream' => false,
        ], $request->toArray(false));
    }

    public function testExtraBodyCanAddResponsesApiFields(): void
    {
        $request = AiRequest::text('gpt-5-mini', 'Hello')
            ->withExtraBody(['reasoning' => ['effort' => 'low']]);

        self::assertSame('low', $request->toArray()['reasoning']['effort']);
    }

    public function testCanSetOutputSchema(): void
    {
        $request = AiRequest::text('gpt-5-mini', 'Hello')
            ->withOutputSchema(
                'AnswerDto',
                [
                    'type' => 'object',
                    'properties' => [
                        'answer' => ['type' => 'string'],
                    ],
                    'required' => ['answer'],
                    'additionalProperties' => false,
                ],
                'Structured answer.',
            );

        self::assertSame([
            'type' => 'json_schema',
            'name' => 'AnswerDto',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'answer' => ['type' => 'string'],
                ],
                'required' => ['answer'],
                'additionalProperties' => false,
            ],
            'strict' => true,
            'description' => 'Structured answer.',
        ], $request->toArray()['text']['format']);
    }

    public function testRejectsEmptyOutputSchemaName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Output schema name must not be empty');

        AiRequest::text('gpt-5-mini', 'Hello')->withOutputSchema('', ['type' => 'object']);
    }

    public function testSerializesToolsFromConstructor(): void
    {
        $request = new AiRequest(
            model: 'gpt-5-mini',
            input: 'Find docs',
            tools: [
                ['type' => 'web_search_preview'],
            ],
        );

        self::assertSame([
            ['type' => 'web_search_preview'],
        ], $request->toArray()['tools']);
    }

    public function testCanSetToolsFromToolTypes(): void
    {
        $request = AiRequest::text('gpt-5-mini', 'Find docs')->withTools(
            new WebAccessTool(),
            new CodeInterpreterTool(['container' => ['type' => 'auto']]),
        );

        self::assertSame([
            ['type' => 'web_search_preview'],
            ['type' => 'code_interpreter', 'container' => ['type' => 'auto']],
        ], $request->toArray()['tools']);
    }
}
