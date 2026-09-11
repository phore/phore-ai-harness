<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test\Usage;

use Phore\AiHarness\Usage\CostEstimator;
use Phore\AiHarness\Usage\GlobalUsage;
use Phore\AiHarness\Result\UsageInfoType;
use PHPUnit\Framework\TestCase;

final class GlobalUsageTest extends TestCase
{
    public function testAggregatesModelsCachesErrorsAndDetachedSnapshots(): void
    {
        $usage = new GlobalUsage();
        self::assertSame(0.0, $usage->getStats()['totalCostUsd']);
        $first = $usage->begin('gpt-5-mini');
        $second = $usage->begin('gpt-5-nano');
        self::assertSame(2, $usage->getStats()['pendingRequests']);
        self::assertNull($usage->getStats()['totalCostUsd']);
        $body = ['model' => 'gpt-5-mini-2025-08-07', 'usage' => [
            'input_tokens' => 1000, 'output_tokens' => 100,
            'input_tokens_details' => ['cached_tokens' => 400],
            'output_tokens_details' => ['reasoning_tokens' => 50],
        ]];
        $usage->finish($first, $body);
        $snapshot = $usage->getStats();
        $usage->finish($first, $body, true); // Must never count twice.
        $usage->finish($second, ['status' => 'incomplete', 'usage' => [
            'input_tokens' => 1000, 'output_tokens' => 100,
        ]]);
        $stats = $usage->getStats();
        self::assertSame(2, $stats['requests']);
        self::assertSame(1, $stats['errors']);
        self::assertSame(0, $stats['pendingRequests']);
        self::assertSame(2000, $stats['inputTokens']);
        self::assertSame(200, $stats['outputTokens']);
        self::assertSame(2200, $stats['totalTokens']);
        self::assertSame(400, $stats['cachedInputTokens']);
        self::assertSame(50, $stats['reasoningOutputTokens']);
        self::assertEqualsWithDelta(0.00045, $stats['totalCostUsd'], 1e-12);
        self::assertArrayNotHasKey('gpt-5-mini', $stats['models']);
        self::assertSame(1, $snapshot['pendingRequests']);
        self::assertNull($snapshot['totalCostUsd']);
        self::assertSame($stats, $usage->getStats());
    }

    public function testUnknownPricesAndMissingUsageAreExplicitAndCanBeRepriced(): void
    {
        $usage = new GlobalUsage();
        $usage->finish($usage->begin('custom'), ['usage' => ['input_tokens' => 100, 'output_tokens' => 20]]);
        $stats = $usage->getStats();
        self::assertSame(1, $stats['unpricedRequests']);
        self::assertNull($stats['totalCostUsd']);
        $estimator = new CostEstimator(['custom' => ['input' => 1.0, 'cachedInput' => null, 'output' => 5.0]]);
        self::assertEqualsWithDelta(0.0002, $usage->getStats($estimator)['totalCostUsd'], 1e-12);
        $usage->finish($usage->begin('gpt-5'), failed: true);
        $stats = $usage->getStats($estimator);
        self::assertSame(1, $stats['missingUsageRequests']);
        self::assertSame(1, $stats['errors']);
        self::assertNull($stats['totalCostUsd']);
        self::assertEqualsWithDelta(0.0002, $stats['knownCostUsd'], 1e-12);
    }

    public function testEstimatorSharesSingleResponsePricesAndRejectsUnknownVariants(): void
    {
        $estimator = new CostEstimator();
        self::assertSame([null, null, null], $estimator->estimate('gpt-5-unknown', 100, 0, 10));
        self::assertSame([null, null, null], $estimator->estimate('gpt-5.999', 100, 0, 10));
        $body = ['model' => 'gpt-5-mini', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]];
        self::assertSame(UsageInfoType::fromResponseBody($body)->totalCostUsd, $estimator->estimate('gpt-5-mini', 100, 0, 10)[2]);
        $this->expectException(\InvalidArgumentException::class);
        new CostEstimator(['bad' => ['input' => -1.0, 'output' => 1.0]]);
    }
}
