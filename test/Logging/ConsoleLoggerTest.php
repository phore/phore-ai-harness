<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test\Logging;

use Phore\AiHarness\Logging\ConsoleLogger;
use Phore\AiHarness\Logging\LogEvent;
use Phore\AiHarness\Logging\Redactor;
use PHPUnit\Framework\TestCase;

final class ConsoleLoggerTest extends TestCase
{
    public function testChunksDoNotRepeatPrefixesAndFinalLineIsFlushed(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($stream);
        foreach (["Fir", "st\nSec", 'ond'] as $chunk) {
            $logger->log(new LogEvent('model', 'text_delta', message: $chunk));
        }
        $logger->log(new LogEvent('model', 'response_end'));
        rewind($stream);
        self::assertSame("[model] First\n[model] Second\n", stream_get_contents($stream));
        fclose($stream);
    }

    public function testRedirectedWarningsNeverHaveAnsiCodes(): void
    {
        $stream = fopen('php://memory', 'w+');
        (new ConsoleLogger($stream))->log(new LogEvent('model', 'warning', 'warning', 'Correction required'));
        rewind($stream);
        self::assertSame("[model] WARNING Correction required\n", stream_get_contents($stream));
        fclose($stream);
    }

    public function testTerminalWarningsRespectNoColor(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('proc_open')) {
            self::markTestSkipped('This test needs Linux pseudo terminals.');
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; '
            . '$logger = new \\Phore\\AiHarness\\Logging\\ConsoleLogger(STDOUT); '
            . '$logger->log(new \\Phore\\AiHarness\\Logging\\LogEvent("model", "warning", "warning", "Retry"));';
        $oldColor = getenv('NO_COLOR');
        $oldTerm = getenv('TERM');
        try {
            putenv('TERM=xterm');
            foreach ([false, true] as $noColor) {
                putenv($noColor ? 'NO_COLOR=1' : 'NO_COLOR');
                $process = proc_open([PHP_BINARY, '-r', $code],
                    [0 => ['pipe', 'r'], 1 => ['pty'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
                self::assertStringContainsString('[model] WARNING Retry', $output);
                if ($noColor) {
                    self::assertStringNotContainsString("\033[", $output);
                } else {
                    self::assertStringContainsString("\033[31m[model] WARNING Retry\033[0m", $output);
                }
            }
        } finally {
            putenv($oldColor === false ? 'NO_COLOR' : 'NO_COLOR=' . $oldColor);
            putenv($oldTerm === false ? 'TERM' : 'TERM=' . $oldTerm);
        }
    }

    public function testRedactionIncludesNestedArgumentsAndInvalidJson(): void
    {
        $safe = Redactor::arguments('{"city":"Berlin","nested":{"api_key":"secret-value"},"password":"hidden"}');
        self::assertSame('Berlin', $safe['city']);
        self::assertSame('[REDACTED]', $safe['nested']['api_key']);
        self::assertSame('[REDACTED]', $safe['password']);
        self::assertSame('[invalid JSON omitted]', Redactor::arguments('{"api_key":"hidden"'));
        self::assertStringNotContainsString('sk-secret123', Redactor::text('Bearer sk-secret123'));
    }
}
