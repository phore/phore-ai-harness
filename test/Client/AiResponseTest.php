<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiResponse;
use PHPUnit\Framework\TestCase;

final class AiResponseTest extends TestCase
{
    public function testReadsOutputTextFromConvenienceField(): void
    {
        $response = AiResponse::fromJson(
            200,
            ['content-type' => ['application/json']],
            '{"id":"resp_123","status":"completed","output_text":"Hello world"}',
        );

        self::assertSame('resp_123', $response->getId());
        self::assertTrue($response->isCompleted());
        self::assertSame('application/json', $response->getHeader('Content-Type'));
        self::assertSame('Hello world', $response->getOutputText());
    }

    public function testReadsOutputTextFromResponsesOutputArray(): void
    {
        $response = new AiResponse(200, [], [
            'output' => [[
                'type' => 'message',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Hello '],
                    ['type' => 'output_text', 'text' => 'world'],
                ],
            ]],
        ], '');

        self::assertSame('Hello world', $response->getOutputText());
    }

    public function testReadsUsageAndCalculatesCost(): void
    {
        $response = new AiResponse(200, [], [
            'model' => 'gpt-5-mini',
            'usage' => [
                'input_tokens' => 1000,
                'input_tokens_details' => ['cached_tokens' => 200],
                'output_tokens' => 500,
                'output_tokens_details' => ['reasoning_tokens' => 50],
                'total_tokens' => 1500,
            ],
        ], '');

        $usage = $response->getUsage();

        self::assertSame(1000, $usage->inputTokens);
        self::assertSame(500, $usage->outputTokens);
        self::assertSame(1500, $usage->totalTokens);
        self::assertSame(200, $usage->cachedInputTokens);
        self::assertSame(50, $usage->reasoningOutputTokens);
        self::assertSame('gpt-5-mini', $usage->model);
        self::assertEqualsWithDelta(0.000205, $usage->inputCostUsd, 0.000000001);
        self::assertEqualsWithDelta(0.001, $usage->outputCostUsd, 0.000000001);
        self::assertEqualsWithDelta(0.001205, $usage->totalCostUsd, 0.000000001);
        self::assertSame('$0.001205', $usage->formatTotalCostUsd());
        self::assertSame('tokens in=1000 out=500 total=1500 cost=$0.001205', (string)$usage);
    }

    public function testUsageFallsBackToTotalTokensAndUnknownCost(): void
    {
        $response = new AiResponse(200, [], [
            'model' => 'unknown-model',
            'usage' => [
                'input_tokens' => '3',
                'output_tokens' => 4,
            ],
        ], '');

        $usage = $response->getUsage();

        self::assertSame(3, $usage->inputTokens);
        self::assertSame(4, $usage->outputTokens);
        self::assertSame(7, $usage->totalTokens);
        self::assertNull($usage->totalCostUsd);
        self::assertNull($usage->formatTotalCostUsd());
    }
}
