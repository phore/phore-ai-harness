<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\CodeInterpreterTool;
use Phore\AiHarness\ToolType\WebAccessTool;
use PHPUnit\Framework\TestCase;

function aiRequestTestCallbackTool(string $query): string
{
    return $query;
}

final class AiRequestTest extends TestCase
{
    public function testFollowUpPreservesSettingsWithoutMutatingOriginal(): void
    {
        $request = new AiRequest(
            model: 'gpt-5-mini',
            input: 'Original prompt',
            instructions: 'Keep the required format',
            maxOutputTokens: 123,
            temperature: 0.2,
            text: ['format' => ['type' => 'json_object']],
            metadata: ['tenant' => 'test'],
            previousResponseId: 'response-original',
            tools: [['type' => 'function', 'name' => 'sample']],
            toolChoice: 'auto',
            parallelToolCalls: false,
            stream: true,
            extraBody: ['reasoning' => ['effort' => 'low']],
        );
        $originalProperties = get_object_vars($request);
        $originalPayload = $request->toArray();
        $outputs = [['type' => 'function_call_output', 'call_id' => 'call-1', 'output' => '7']];

        $followUp = $request->withFollowUp($outputs, 'response-next');

        self::assertNotSame($request, $followUp);
        self::assertSame($originalProperties, get_object_vars($request));
        self::assertSame($originalPayload, $request->toArray());
        self::assertSame(array_replace($originalProperties, [
            'input' => $outputs, 'previousResponseId' => 'response-next',
        ]), get_object_vars($followUp));
        self::assertSame(array_replace($originalPayload, [
            'input' => $outputs, 'previous_response_id' => 'response-next',
        ]), $followUp->toArray());
    }

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

    public function testCanSetCallbackToolFromToolTypes(): void
    {
        $request = AiRequest::text('gpt-5-mini', 'Search')->withTools(
            new CallbackTool('aiRequestTestCallbackTool', name: 'search_local'),
        );

        self::assertSame('function', $request->toArray()['tools'][0]['type']);
        self::assertSame('search_local', $request->toArray()['tools'][0]['name']);
        self::assertSame('string', $request->toArray()['tools'][0]['parameters']['properties']['query']['type']);
    }
}
