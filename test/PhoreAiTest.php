<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Keystore\Keystore;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\CodeInterpreterTool;
use Phore\AiHarness\ToolType\WebAccessTool;
use PHPUnit\Framework\TestCase;

final class PhoreAiTest extends TestCase
{
    protected function tearDown(): void
    {
        Keystore::resetInstance();
    }

    public function testAcceptsOpenAiClientInstance(): void
    {
        $client = new OpenAiClient('test-key');
        $phoreAi = new PhoreAi($client);

        self::assertSame($client, $phoreAi->getOpenAiClient());
    }

    public function testAcceptsOpenAiDsn(): void
    {
        $phoreAi = new PhoreAi('openai:test-key');

        self::assertInstanceOf(OpenAiClient::class, $phoreAi->getOpenAiClient());
    }

    public function testRejectsUnsupportedDsn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported AI client DSN');

        new PhoreAi('anthropic:test-key');
    }

    public function testUsesKeystoreWhenOpenAiDsnHasNoApiKey(): void
    {
        Keystore::resetInstance();
        Keystore::instance()->addKey('keystore-key');

        $phoreAi = new PhoreAi('openai:');

        self::assertSame('keystore-key', $this->readOpenAiClientApiKey($phoreAi->getOpenAiClient()));
    }

    public function testDefaultConstructorUsesKeystore(): void
    {
        Keystore::resetInstance();
        Keystore::instance()->addKey('default-key');

        $phoreAi = new PhoreAi();

        self::assertSame('default-key', $this->readOpenAiClientApiKey($phoreAi->getOpenAiClient()));
    }

    public function testRejectsEmptyModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model must not be empty');

        (new PhoreAi('openai:test-key'))->withModel('');
    }

    public function testRunCastedRejectsUnknownOutputClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Output class does not exist');

        (new PhoreAi('openai:test-key'))->runCasted('MissingOutputClass');
    }

    public function testRunCastedArrayRejectsUnknownOutputClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Output class does not exist');

        (new PhoreAi('openai:test-key'))->runCastedArray('MissingOutputClass');
    }

    public function testCanConfigureToolsThroughWith(): void
    {
        $phoreAi = (new PhoreAi('openai:test-key'))->with(
            new TextPrompt('Use the tools.'),
            new WebAccessTool(),
            new CodeInterpreterTool(['container' => ['type' => 'auto']]),
        );

        self::assertCount(1, $this->readProperty($phoreAi, 'prompts'));
        self::assertEquals([
            new WebAccessTool(),
            new CodeInterpreterTool(['container' => ['type' => 'auto']]),
        ], $this->readProperty($phoreAi, 'tools'));
    }

    private function readOpenAiClientApiKey(OpenAiClient $client): string
    {
        return $this->readProperty($client, 'apiKey');
    }

    private function readProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
