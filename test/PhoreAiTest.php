<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Keystore\Keystore;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\CodeInterpreterTool;
use Phore\AiHarness\ToolType\RecoverableToolException;
use Phore\AiHarness\ToolType\TaskErrorException;
use Phore\AiHarness\ToolType\TaskErrorTool;
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

    public function testInvokesParameterlessCallbackToolWithEmptyJsonObjectArguments(): void
    {
        $phoreAi = new PhoreAi('openai:test-key');
        $method = new ReflectionMethod($phoreAi, 'invokeCallbackTool');

        $result = $method->invoke($phoreAi, new CallbackTool(
            static fn (): string => 'file content',
            name: 'get_file_content',
        ), '{}');

        self::assertSame('file content', $result);
    }

    public function testRejectsCallbackToolArgumentsThatAreJsonArray(): void
    {
        $phoreAi = new PhoreAi('openai:test-key');
        $method = new ReflectionMethod($phoreAi, 'invokeCallbackTool');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Callback tool arguments must decode to a JSON object.');

        $method->invoke($phoreAi, new CallbackTool(
            static fn (): string => 'file content',
            name: 'get_file_content',
        ), '[]');
    }

    public function testReturnsRecoverableCallbackToolExceptionToModel(): void
    {
        $phoreAi = new PhoreAi('openai:test-key');
        $method = new ReflectionMethod($phoreAi, 'invokeCallbackTool');

        $result = $method->invoke($phoreAi, new CallbackTool(
            static fn (): never => throw new RecoverableToolException('File not found. Check the path and retry.'),
            name: 'read_file',
        ), '{}');

        self::assertSame([
            'ok' => false,
            'error' => [
                'type' => 'recoverable_tool_error',
                'message' => 'File not found. Check the path and retry.',
                'retryable' => true,
            ],
            'instruction' => 'Correct the tool input or choose another approach, then continue.',
        ], json_decode($result, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testPropagatesRegularCallbackToolException(): void
    {
        $phoreAi = new PhoreAi('openai:test-key');
        $method = new ReflectionMethod($phoreAi, 'invokeCallbackTool');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection failed.');

        $method->invoke($phoreAi, new CallbackTool(
            static fn (): never => throw new RuntimeException('Database connection failed.'),
            name: 'load_record',
        ), '{}');
    }

    public function testPropagatesModelTriggeredTaskErrorException(): void
    {
        $phoreAi = new PhoreAi('openai:test-key');
        $method = new ReflectionMethod($phoreAi, 'invokeCallbackTool');

        $this->expectException(TaskErrorException::class);

        $method->invoke($phoreAi, new TaskErrorTool(), json_encode([
            'errorType' => 'missing_information',
            'contradictoryOrAmbiguousStatements' => 'n/a',
            'missingInformation' => 'deployment target',
            'requestedAt' => 'ask the user',
            'reason' => 'The target determines the deployment procedure.',
        ], JSON_THROW_ON_ERROR));
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
