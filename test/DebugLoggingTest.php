<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use Phore\AiHarness\Client\AiRequestException;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\Logging\ConsoleLogger;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\Logging\LogEvent;
use Phore\AiHarness\Logging\RunStatistics;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\TaskErrorException;
use Phore\AiHarness\ToolType\RecoverableToolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DebugValue
{
    public int $value;
}

final class RecordingDebugLogger implements LoggerInterface
{
    public array $events = [];
    public function log(LogEvent $event): void { $this->events[] = $event; }
    public function stats(): RunStatistics
    {
        $events = array_values(array_filter($this->events, fn ($event) => $event->type === 'stats'));
        TestCase::assertCount(1, $events);
        return $events[0]->statistics;
    }
}

final class DebugLoggingTest extends TestCase
{
    private static $server;
    private static array $pipes = [];
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is needed for the local HTTP fixture.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::markTestSkipped('Loopback sockets unavailable.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$baseUrl = 'http://' . $address;
        self::$server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/debug-server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], self::$pipes);
        if (!is_resource(self::$server)) {
            self::fail('Cannot start local HTTP fixture.');
        }
        for ($i = 0; $i < 100; $i++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(10000);
        }
        self::tearDownAfterClass();
        self::fail('Local HTTP fixture did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            foreach (self::$pipes as $pipe) {
                fclose($pipe);
            }
            proc_close(self::$server);
        }
    }

    private function client(string $scenario): OpenAiClient
    {
        return new OpenAiClient('fixture-key', baseUrl: self::$baseUrl . '/' . $scenario, timeout: 2);
    }

    public function testDefaultIsNonStreamingAndLoggerConfigurationIsImmutable(): void
    {
        $ai = new PhoreAi($this->client('mode'));
        $logger = new RecordingDebugLogger();
        $debug = $ai->withLogger($logger);
        self::assertSame('json', $ai->run());
        self::assertSame('stream', $debug->run());
        self::assertSame('json', $debug->withLogger(null)->run());
        self::assertSame(1, $logger->stats()->requests);
    }

    public function testStreamingDoesNotChangeTheReturnedText(): void
    {
        $plain = new PhoreAi($this->client('text'));
        $logger = new RecordingDebugLogger();
        self::assertSame($plain->run(), $plain->withLogger($logger)->run());
        self::assertSame(12, $logger->stats()->tokens_total);
    }

    public function testCorrectsExplicitRecoverableCallsAndAggregatesEveryRound(): void
    {
        $scenario = 'retry';
        $logger = new RecordingDebugLogger();
        $values = [];
        $ai = (new PhoreAi($this->client($scenario)))->withLogger($logger)->with(new CallbackTool(
            static function (int $value) use (&$values): int {
                if ($value === 0) {
                    throw new RecoverableToolException('Value must be positive.');
                }
                $values[] = $value;
                return $value;
            }, name: 'sample',
        ));
        self::assertSame("First line\nSecond line", $ai->run());
        self::assertSame([7], $values);
        $stats = $logger->stats();
        self::assertSame(3, $stats->requests);
        self::assertSame(2, $stats->tool_calls);
        self::assertSame(1, $stats->errors);
        self::assertSame(1, $stats->retries);
        self::assertSame(30, $stats->tokens_in);
        self::assertSame(6, $stats->tokens_out);
        self::assertSame(36, $stats->tokens_total);
        self::assertGreaterThanOrEqual($stats->duration_api + $stats->duration_tools, $stats->duration_total);
        self::assertCount(2, array_filter($logger->events, fn ($event) => $event->type === 'tool_start'));
        self::assertCount(2, array_filter($logger->events, fn ($event) => $event->type === 'tool_end'));
    }

    public function testTwoFailedCallsCauseOnlyOneRetryRequest(): void
    {
        $logger = new RecordingDebugLogger();
        (new PhoreAi($this->client('two-errors')))->withLogger($logger)->with(
            new CallbackTool(static function (int $value): int { throw new RecoverableToolException('Correct the value.'); }, name: 'sample'),
        )->run();
        self::assertSame(2, $logger->stats()->errors);
        self::assertSame(1, $logger->stats()->retries);
    }

    public function testWithoutLoggingInvalidCallStillThrowsImmediately(): void
    {
        $this->expectException(\JsonException::class);
        (new PhoreAi($this->client('invalid-json')))->with(
            new CallbackTool(static function (int $value): int { throw new RecoverableToolException('Correct the value.'); }, name: 'sample'),
        )->run();
    }

    public function testLimitDoesNotExecuteASixthRoundAndEmitsFailedSummary(): void
    {
        $logger = new RecordingDebugLogger();
        try {
            (new PhoreAi($this->client('limit')))->withLogger($logger)->with(
                new CallbackTool(static function (int $value): int { throw new RecoverableToolException('Correct the value.'); }, name: 'sample'),
            )->run();
            self::fail('Expected round-limit exception.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('round limit', $error->getMessage());
        }
        self::assertSame('failed', $logger->stats()->status);
        self::assertSame(PhoreAi::MAX_CALLBACK_ROUNDS, $logger->stats()->tool_calls);
        self::assertSame(PhoreAi::MAX_CALLBACK_ROUNDS + 1, $logger->stats()->requests);
    }

    public function testOrdinaryExceptionIsFatalWithLoggingAndDoesNotLeakItsMessage(): void
    {
        $logger = new RecordingDebugLogger();
        $original = new \RuntimeException('secret credential: do-not-log');
        try {
            (new PhoreAi($this->client('business')))->withLogger($logger)->with(new CallbackTool(
                static function (int $value) use ($original): int { throw $original; }, name: 'sample',
            ))->run();
            self::fail('Expected ordinary exception.');
        } catch (\RuntimeException $error) {
            self::assertSame($original, $error);
        }
        self::assertSame(0, $logger->stats()->retries);
        self::assertSame(1, $logger->stats()->requests);
        self::assertStringNotContainsString('do-not-log', json_encode($logger->events));
    }

    public static function fatalArgumentScenarios(): array
    {
        return [['invalid-json', \JsonException::class], ['binding', \TypeError::class], ['named', \Error::class]];
    }

    #[DataProvider('fatalArgumentScenarios')]
    public function testArgumentErrorsRemainFatalWithLogging(string $scenario, string $errorClass): void
    {
        $logger = new RecordingDebugLogger();
        $caught = null;
        try {
            (new PhoreAi($this->client($scenario)))->withLogger($logger)->with(
                new CallbackTool(static fn (int $value): int => $value, name: 'sample'),
            )->run();
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertInstanceOf($errorClass, $caught);
        self::assertSame(0, $logger->stats()->retries);
        self::assertSame(1, $logger->stats()->requests);
        self::assertSame('failed', $logger->stats()->status);
    }

    public function testInternalErrorIsNotRetriedAndIsRethrownUnchanged(): void
    {
        $logger = new RecordingDebugLogger();
        $original = new \Error('Internal error');
        try {
            (new PhoreAi($this->client('internal')))->withLogger($logger)->with(new CallbackTool(
                static function (int $value) use ($original): int { throw $original; }, name: 'sample',
            ))->run();
            self::fail('Expected internal error.');
        } catch (\Error $error) {
            self::assertSame($original, $error);
        }
        self::assertSame(0, $logger->stats()->retries);
        self::assertSame(1, $logger->stats()->errors);
        self::assertSame('failed', $logger->stats()->status);
    }

    public function testTaskErrorIsRethrownUnchangedWithoutRetry(): void
    {
        $logger = new RecordingDebugLogger();
        $original = new TaskErrorException('missing', '', 'value', 'fixture', 'cannot proceed');
        try {
            (new PhoreAi($this->client('task')))->withLogger($logger)->with(new CallbackTool(
                static function (int $value) use ($original): int { throw $original; }, name: 'sample',
            ))->run();
            self::fail('Expected task error.');
        } catch (TaskErrorException $error) {
            self::assertSame($original, $error);
        }
        self::assertSame(0, $logger->stats()->retries);
        self::assertSame(1, $logger->stats()->errors);
    }

    public function testSerializationFailureDoesNotRepeatCallback(): void
    {
        $logger = new RecordingDebugLogger();
        $calls = 0;
        try {
            (new PhoreAi($this->client('serialize')))->withLogger($logger)->with(new CallbackTool(
                static function (int $value) use (&$calls): float { $calls++; return INF; }, name: 'sample',
            ))->run();
            self::fail('Expected serialization failure.');
        } catch (\JsonException) {
        }
        self::assertSame(1, $calls);
        self::assertSame(0, $logger->stats()->retries);
        self::assertSame('failed', $logger->stats()->status);
    }

    public function testTransportFailureIsNotRetried(): void
    {
        $logger = new RecordingDebugLogger();
        try {
            (new PhoreAi($this->client('transport')))->withLogger($logger)->run();
            self::fail('Expected transport failure.');
        } catch (AiRequestException) {
        }
        self::assertSame(1, $logger->stats()->requests);
        self::assertSame(0, $logger->stats()->retries);
        self::assertSame('failed', $logger->stats()->status);
    }

    public function testStructAndArrayFunctionsEmitOneSummaryAfterHydration(): void
    {
        foreach (['struct', 'array'] as $scenario) {
            $logger = new RecordingDebugLogger();
            $options = ['client' => $this->client($scenario), 'debug_log' => $logger];
            $result = $scenario === 'struct' ? phore_ai_struct('test', DebugValue::class, $options)
                : phore_ai_struct_array('test', DebugValue::class, $options);
            self::assertSame(7, ($scenario === 'struct' ? $result : $result[0])->value);
            self::assertSame('ok', $logger->stats()->status);
        }
    }

    public function testHydrationPreparationFailureStillProducesOneFailedSummary(): void
    {
        $logger = new RecordingDebugLogger();
        try {
            (new PhoreAi($this->client('text')))->withLogger($logger)->runCasted('MissingDebugClass');
            self::fail('Expected missing class exception.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('failed', $logger->stats()->status);
        self::assertSame(0, $logger->stats()->requests);
    }

    public function testInvalidStructuredOutputIsReportedAsFailed(): void
    {
        $logger = new RecordingDebugLogger();
        try {
            phore_ai_struct('test', DebugValue::class, ['client' => $this->client('invalid-struct'), 'debug_log' => $logger]);
            self::fail('Expected invalid JSON.');
        } catch (\JsonException) {
        }
        self::assertSame('failed', $logger->stats()->status);
    }

    public function testImageUsesJsonAndFileUsesStreamingWithLogging(): void
    {
        $logger = new RecordingDebugLogger();
        $image = phore_ai_image('test', ['client' => $this->client('image'), 'debug_log' => $logger]);
        self::assertSame('image-bytes', $image->data);
        self::assertSame('ok', $logger->stats()->status);
        $file = tempnam(sys_get_temp_dir(), 'harness-debug-');
        try {
            file_put_contents($file, 'original');
            $logger = new RecordingDebugLogger();
            phore_ai_edit_file('test', $file, options: ['client' => $this->client('file'), 'debug_log' => $logger]);
            self::assertSame('updated', file_get_contents($file));
            self::assertSame(1, $logger->stats()->tool_calls);
            self::assertSame(2, $logger->stats()->requests);
        } finally {
            unlink($file);
        }
    }

    public function testTextFunctionAndMissingUsage(): void
    {
        $logger = new RecordingDebugLogger();
        self::assertSame("First line\nSecond line", phore_ai_text('test', ['client' => $this->client('no-usage'), 'debug_log' => $logger]));
        self::assertNull($logger->stats()->tokens_total);
    }

    public function testTrueCreatesConsoleLoggerAndInvalidOptionsFailBeforeClientConstruction(): void
    {
        $ai = Toolkit::createAi(['client' => $this->client('mode'), 'debug_log' => true]);
        self::assertInstanceOf(ConsoleLogger::class, (new \ReflectionProperty($ai, 'logger'))->getValue($ai));
        foreach ([null, 1, 'yes', [], new \stdClass()] as $invalid) {
            try {
                Toolkit::createAi(['client' => 'invalid-dsn', 'debug_log' => $invalid]);
                self::fail('Expected invalid debug_log exception.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('debug_log', $error->getMessage());
            }
        }
    }
}
