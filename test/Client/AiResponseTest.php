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
}
