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
        self::assertSame(0, $stats['unpricedRequests']);
        self::assertSame(1, $stats['fallbackRequests']);
        self::assertSame('highest-fallback', $stats['models']['custom']['pricingSource']);
        self::assertEqualsWithDelta(0.0076, $stats['totalCostUsd'], 1e-12);
        $estimator = new CostEstimator(['custom' => ['input' => 1.0, 'cachedInput' => null, 'output' => 5.0]]);
        self::assertEqualsWithDelta(0.0002, $usage->getStats($estimator)['totalCostUsd'], 1e-12);
        $usage->finish($usage->begin('gpt-5'), failed: true);
        $stats = $usage->getStats($estimator);
        self::assertSame(1, $stats['missingUsageRequests']);
        self::assertSame(1, $stats['errors']);
        self::assertNull($stats['totalCostUsd']);
        self::assertEqualsWithDelta(0.0002, $stats['knownCostUsd'], 1e-12);
    }

    public function testEstimatorSharesSingleResponsePricesAndEstimatesUnknownVariants(): void
    {
        $estimator = new CostEstimator();
        self::assertEqualsWithDelta(0.0058, $estimator->estimate('gpt-5-unknown', 100, 0, 10)[2], 1e-12);
        self::assertEqualsWithDelta(0.0058, $estimator->estimate('gpt-5.999', 100, 0, 10)[2], 1e-12);
        $body = ['model' => 'gpt-5-mini', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]];
        self::assertSame(UsageInfoType::fromResponseBody($body)->totalCostUsd, $estimator->estimate('gpt-5-mini', 100, 0, 10)[2]);
        $this->expectException(\InvalidArgumentException::class);
        new CostEstimator(['bad' => ['input' => -1.0, 'output' => 1.0]]);
    }

    public function testRegexFamiliesPremiumPriorityAndCacheConservatism(): void
    {
        $estimator = new CostEstimator();
        foreach (['future-mini', 'GPT-9-MINI-2028-01-01', 'provider/model_mini'] as $model) {
            self::assertSame('mini-fallback', $estimator->getPriceInfo($model)['source']);
            self::assertSame([0.75, 4.5, 5.25], $estimator->estimate($model, 1_000_000, 1_000_000, 1_000_000));
        }
        self::assertSame([0.2, 1.25, 1.45], $estimator->estimate('future-nano', 1_000_000, 1_000_000, 1_000_000));
        foreach (['future-mini-pro', 'future-ultra-nano', 'minified', 'gpt-99', 'unknown'] as $model) {
            self::assertSame('highest-fallback', $estimator->getPriceInfo($model)['source']);
            self::assertSame([40.0, 180.0, 220.0], $estimator->estimate($model, 1_000_000, 1_000_000, 1_000_000));
        }
        self::assertSame([null, null, null], $estimator->estimate(null, 10, 0, 10));
        self::assertSame([null, null, null], $estimator->estimate(' ', 10, 0, 10));
    }

    public function testCurrentModelsSnapshotsAndOverridesTakePrecedence(): void
    {
        $estimator = new CostEstimator();
        self::assertSame([20.0, 75.0, 95.0], $estimator->estimate('gpt-6-astra', 1_000_000, 0, 1_000_000));
        self::assertSame([0.4, 1.8, 2.2], $estimator->estimate('gpt-5.6-luna', 1_000_000, 0, 1_000_000));
        self::assertSame('snapshot', $estimator->getPriceInfo('gpt-5.4-mini-2026-03-17')['source']);
        self::assertSame([0.075, 4.5, 4.575], $estimator->estimate('gpt-5.4-mini-2026-03-17', 1_000_000, 1_000_000, 1_000_000));
        $custom = new CostEstimator([
            'future-mini' => ['input' => 0.1, 'cachedInput' => 0.01, 'output' => 0.2],
            'expensive' => ['input' => 100.0, 'cachedInput' => null, 'output' => 300.0],
            'gpt-5.4-mini' => ['input' => 0.1, 'output' => 0.2],
        ]);
        self::assertSame('exact', $custom->getPriceInfo('future-mini')['source']);
        self::assertEqualsWithDelta(0.21, $custom->estimate('future-mini', 1_000_000, 1_000_000, 1_000_000)[2], 1e-12);
        self::assertSame([0.75, 4.5, 5.25], $custom->estimate('other-mini', 1_000_000, 0, 1_000_000));
        self::assertSame([100.0, 300.0, 400.0], $custom->estimate('other-large', 1_000_000, 1_000_000, 1_000_000));
    }
}
