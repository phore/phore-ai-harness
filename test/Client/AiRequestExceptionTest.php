<?php

declare(strict_types=1);

use Phore\AiHarness\Client\AiRequestException;
use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Client\OpenAiClient;
use PHPUnit\Framework\TestCase;

final class AiRequestExceptionTest extends TestCase
{
    public function testExceptionContainsFailedResponse(): void
    {
        $response = AiResponse::fromJson(
            429,
            ['content-type' => ['application/json']],
            '{"error":{"message":"Rate limit reached"}}',
        );

        $exception = AiRequestException::fromResponse($response);

        self::assertSame('Rate limit reached', $exception->getMessage());
        self::assertSame(429, $exception->getCode());
        self::assertSame($response, $exception->response);
    }

    public function testClientThrowsExceptionForErrorStatusCode(): void
    {
        $client = new OpenAiClient('test-key');
        $response = AiResponse::fromJson(500, [], '{"error":{"message":"Server error"}}');

        $this->expectException(AiRequestException::class);
        $this->expectExceptionMessage('Server error');

        $client->assertSuccessfulResponse($response);
    }
}
