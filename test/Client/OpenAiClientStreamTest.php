<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use PHPUnit\Framework\TestCase;

final class OpenAiClientStreamTest extends TestCase
{
    public function testParsesStreamingServerSentEventsAcrossChunks(): void
    {
        $client = new OpenAiClient('test-key');
        $context = $client->createStreamContext();
        $events = [];
        $write = $client->createStreamWriteFunction($context, static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $curl = curl_init('https://example.test');
        self::assertInstanceOf(CurlHandle::class, $curl);

        $write($curl, "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hel");
        $write($curl, "lo\"}\r\n\r\n");

        self::assertSame('Hello', $context->outputText);
        self::assertSame('response.output_text.delta', $events[0]['type']);
        self::assertSame($events, $context->events);
    }
}
