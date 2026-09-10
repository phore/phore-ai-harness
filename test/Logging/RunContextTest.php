<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test\Logging;

use Phore\AiHarness\Client\AiResponse;
use Phore\AiHarness\Logging\LoggerInterface;
use Phore\AiHarness\Logging\LogEvent;
use Phore\AiHarness\Logging\RunContext;
use PHPUnit\Framework\TestCase;

final class RunContextTest extends TestCase
{
    public function testUsageAggregationRedactionAndExactlyOneSummary(): void
    {
        $logger = new class implements LoggerInterface {
            public array $events = [];
            public function log(LogEvent $event): void { $this->events[] = $event; }
        };
        $context = new RunContext($logger, 'model');
        foreach ([['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12], [], ['input_tokens' => 5]] as $usage) {
            $context->addResponse(new AiResponse(200, [], ['usage' => $usage], ''));
        }
        $context->text('sk-sec');
        $context->text("ret123\n");
        $context->emit('tool_start', ['args' => ['password' => 'hidden']]);
        $context->finish('ok');
        $context->finish('failed');
        $stats = array_values(array_filter($logger->events, fn ($event) => $event->type === 'stats'));
        self::assertCount(1, $stats);
        self::assertSame(15, $stats[0]->statistics->tokens_in);
        self::assertSame(2, $stats[0]->statistics->tokens_out);
        self::assertGreaterThanOrEqual(0, $stats[0]->statistics->duration_total);
        self::assertStringNotContainsString('secret123', json_encode($logger->events));
        self::assertStringNotContainsString('hidden', json_encode($logger->events));
    }

    public function testMissingUsageIsUnavailableAndRepeatedErrorIsCountedOnce(): void
    {
        $logger = new class implements LoggerInterface {
            public ?LogEvent $last = null;
            public function log(LogEvent $event): void { $this->last = $event; }
        };
        $context = new RunContext($logger, 'model');
        $error = new \RuntimeException('password=secret');
        $context->error($error);
        $context->error($error);
        $context->finish('failed');
        self::assertNull($logger->last->statistics->tokens_total);
        self::assertSame(1, $logger->last->statistics->errors);
    }
}
