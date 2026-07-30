<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Keystore\Keystore;
use PHPUnit\Framework\TestCase;

final class OpenAiClientStreamTest extends TestCase
{
    protected function tearDown(): void
    {
        Keystore::resetInstance();
    }

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

    public function testConstructorUsesKeystoreWhenNoApiKeyIsGiven(): void
    {
        Keystore::resetInstance();
        Keystore::instance()->addKey('constructor-keystore');

        $client = new OpenAiClient();

        self::assertSame('constructor-keystore', $this->readApiKey($client));
    }

    public function testConstructorUsesKeystoreWhenEmptyApiKeyIsGiven(): void
    {
        Keystore::resetInstance();
        Keystore::instance()->addKey('empty-constructor-keystore');

        $client = new OpenAiClient('');

        self::assertSame('empty-constructor-keystore', $this->readApiKey($client));
    }

    private function readApiKey(OpenAiClient $client): string
    {
        $reflection = new ReflectionProperty($client, 'apiKey');

        return $reflection->getValue($client);
    }
}
