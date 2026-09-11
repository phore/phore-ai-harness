<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use Phore\AiHarness\Client\AiRequest;
use Phore\AiHarness\Client\AiRequestException;
use Phore\AiHarness\Client\AiRequestSpooler;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\Logging\LogEvent;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\ToolType\CallbackTool;
use PHPUnit\Framework\TestCase;

final class GlobalUsageIntegrationTest extends TestCase
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


    public function testCountsAcrossInstancesLoggingAndFollowUps(): void
    {
        $before = get_ai_usage_stats();
        (new PhoreAi($this->client('text')))->run();
        (new PhoreAi($this->client('text')))->withLogger(new class implements LoggerInterface {
            public function log(LogEvent $event): void {}
        })->run();
        (new PhoreAi($this->client('business')))->with(new CallbackTool(
            static fn (int $value): int => $value, name: 'sample',
        ))->run();
        $stats = get_ai_usage_stats();
        self::assertSame(4, $stats['requests'] - $before['requests']);
        self::assertSame(0, $stats['errors'] - $before['errors']);
        self::assertSame(40, $stats['inputTokens'] - $before['inputTokens']);
        self::assertSame(8, $stats['outputTokens'] - $before['outputTokens']);
        self::assertSame(48, $stats['totalTokens'] - $before['totalTokens']);
        self::assertSame(0, $stats['pendingRequests']);
    }

    public function testHttpFailureAndMissingUsageCountOnce(): void
    {
        $before = get_ai_usage_stats();
        try {
            $this->client('transport')->createResponse(AiRequest::text('gpt-5-mini', 'test'));
            self::fail('Expected HTTP error.');
        } catch (AiRequestException) {
        }
        $this->client('no-usage')->createResponse(AiRequest::text('gpt-5-nano', 'test'));
        $stats = get_ai_usage_stats();
        self::assertSame(2, $stats['requests'] - $before['requests']);
        self::assertSame(1, $stats['errors'] - $before['errors']);
        self::assertSame(2, $stats['missingUsageRequests'] - $before['missingUsageRequests']);
        self::assertNull($stats['totalCostUsd']);
    }

    public function testSpoolerCountsJsonAndStreamingWithoutDoubleCounting(): void
    {
        $before = get_ai_usage_stats();
        $responses = (new AiRequestSpooler($this->client('text')))
            ->add(AiRequest::text('gpt-5-mini', 'test'))
            ->addStream(AiRequest::text('gpt-5-nano', 'test'), static function (array $event): void {})
            ->run();
        $stats = get_ai_usage_stats();
        self::assertCount(2, $responses);
        self::assertSame(2, $stats['requests'] - $before['requests']);
        self::assertSame(24, $stats['totalTokens'] - $before['totalTokens']);
        self::assertSame(0, $stats['errors'] - $before['errors']);
        self::assertSame(0, $stats['pendingRequests']);
        self::assertSame($stats, get_ai_usage_stats());
    }

    public function testSpoolerAccountsForHandledHttpFailures(): void
    {
        $before = get_ai_usage_stats();
        $errors = 0;
        $spooler = new AiRequestSpooler($this->client('transport'));
        for ($i = 0; $i < 2; $i++) {
            $spooler->add(AiRequest::text('gpt-5-mini', 'test'), onError: static function () use (&$errors): void { $errors++; });
        }
        self::assertSame([], $spooler->run());
        self::assertSame([], $spooler->run()); // Empty queue must not count.
        $stats = get_ai_usage_stats();
        self::assertSame(2, $errors);
        self::assertSame(2, $stats['requests'] - $before['requests']);
        self::assertSame(2, $stats['errors'] - $before['errors']);
        self::assertSame(0, $stats['pendingRequests']);
    }

    public function testAbortedStreamKeepsUsageAndOriginalException(): void
    {
        $before = get_ai_usage_stats();
        $original = new \RuntimeException('callback');
        try {
            $this->client('text')->streamResponse(AiRequest::text('gpt-5-mini', 'test'),
                static function (array $event) use ($original): void {
                    if (($event['type'] ?? null) === 'response.completed') {
                        throw $original;
                    }
                });
            self::fail('Expected callback error.');
        } catch (\RuntimeException $error) {
            self::assertSame($original, $error);
        }
        $stats = get_ai_usage_stats();
        self::assertSame(1, $stats['requests'] - $before['requests']);
        self::assertSame(1, $stats['errors'] - $before['errors']);
        self::assertSame(12, $stats['totalTokens'] - $before['totalTokens']);
        self::assertSame(0, $stats['pendingRequests']);
    }

    public function testThrowingSpoolerErrorCallbackRetainsOtherCompletedUsage(): void
    {
        $before = get_ai_usage_stats();
        $original = new \RuntimeException('result callback');
        try {
            (new AiRequestSpooler($this->client('text')))
                ->add(AiRequest::text('gpt-5-mini', 'test'),
                    onResponse: static function () use ($original): void { throw $original; },
                    onError: static function () use ($original): void { throw $original; })
                ->add(AiRequest::text('gpt-5-nano', 'test'))
                ->run();
            self::fail('Expected result callback error.');
        } catch (\RuntimeException $error) {
            self::assertSame($original, $error);
        }
        $stats = get_ai_usage_stats();
        self::assertSame(2, $stats['requests'] - $before['requests']);
        self::assertSame(24, $stats['totalTokens'] - $before['totalTokens']);
        self::assertSame(0, $stats['errors'] - $before['errors']);
        self::assertSame(0, $stats['pendingRequests']);
    }
}
