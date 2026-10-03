<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiOptions;
use Phore\AiHarness\Client\AiRequestException;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Edit\FileBatchEditor;
use PHPUnit\Framework\TestCase;

final class ContextEdgeCasesTest extends TestCase
{
    public function testAiOptionsFromArrayPassesInstancesThroughAndMapsKeys(): void
    {
        $options = AiOptions::fromArray([
            'model' => 'gpt-5-mini',
            'reasoning' => ['effort' => 'medium'],
            'debug_log' => true,
        ]);

        self::assertSame($options, AiOptions::fromArray($options));
        self::assertSame('gpt-5-mini', $options->toArray()['model']);
        self::assertSame(['effort' => 'medium'], $options->toArray()['reasoning']);
        self::assertTrue($options->toArray()['debug_log']);
    }

    public function testAiOptionsRejectUnknownArrayKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AI option(s): modle');
        AiOptions::fromArray(['modle' => 'gpt-5-mini']);
    }

    public function testFirstCallClientOverrideRemainsBoundWhenLaterCallsOmitTheOption(): void
    {
        $default = new OpenAiClient('unused', baseUrl: 'http://127.0.0.1:1', timeout: 1);
        $selected = new OpenAiClient('selected', baseUrl: 'http://127.0.0.1:1', timeout: 1);
        $context = new AiContext(options: ['client' => $default]);

        // Absichtlich unerreichbarer Loopback-Client: kein externer Modellaufruf.
        foreach ([['client' => $selected], []] as $options) {
            try {
                $context->text('Test client binding.', options: $options);
                self::fail('The intentionally unreachable client must fail.');
            } catch (AiRequestException) {
            }
            self::assertSame($selected, (new \ReflectionProperty(AiContext::class, 'client'))->getValue($context));
        }

        $this->expectException(InvalidArgumentException::class);
        $context->text('Do not silently switch provider credentials.', options: ['client' => $default]);
    }

    public function testEmptyEditListDoesNotCreateAMissingFile(): void
    {
        $path = sys_get_temp_dir() . '/phore-noop-' . bin2hex(random_bytes(8)) . '.txt';
        try {
            $editor = new FileBatchEditor($path);
            $editor->write([['filename' => 'targetFile1', 'edits' => []]]);
            $editor->assertComplete();
            self::assertSame('unchanged', $editor->results()[0]['status']);
            self::assertFileDoesNotExist($path);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testCliExampleParsesWithoutExecutingIt(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for PHP syntax checking.');
        }
        $process = proc_open(
            [PHP_BINARY, '-l', dirname(__DIR__) . '/examples/01-basic-functions.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
    }
}
