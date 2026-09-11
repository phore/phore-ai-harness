<?php

declare(strict_types=1);

namespace Phore\AiHarness\Usage;

use InvalidArgumentException;

/**
 * Approximate standard text-token prices, USD per million tokens.
 * Source: https://developers.openai.com/api/docs/models/gpt-5
 * and the gpt-5-mini / gpt-5-nano model pages (checked 2026-09-11).
 * Excludes tool fees, image/audio generation, taxes and service-tier adjustments.
 */
final class CostEstimator
{
    /**
     * OpenAI token prices in USD per 1M tokens.
     *
     * @var array<string, array{input: float, cachedInput: float|null, output: float}>
     */
    private const MODEL_PRICES_PER_MILLION_TOKENS_USD = [
        'gpt-5' => ['input' => 1.25, 'cachedInput' => 0.125, 'output' => 10.00],
        'gpt-5-mini' => ['input' => 0.25, 'cachedInput' => 0.025, 'output' => 2.00],
        'gpt-5-nano' => ['input' => 0.05, 'cachedInput' => 0.005, 'output' => 0.40],
    ];

    private array $prices;

    /** @param array<string, array{input: float, cachedInput: float|null, output: float}> $overrides */
    public function __construct(array $overrides = [])
    {
        foreach ($overrides as $model => $rates) {
            if (!is_string($model) || $model === '' || !is_array($rates)) {
                throw new InvalidArgumentException('Expected model names and price arrays.');
            }
            foreach (['input', 'output', 'cachedInput'] as $key) {
                $rate = $rates[$key] ?? null;
                if ($key === 'cachedInput' && $rate === null) {
                    continue;
                }
                if ((!is_int($rate) && !is_float($rate)) || !is_finite((float) $rate) || $rate < 0) {
                    throw new InvalidArgumentException('Token prices must be finite non-negative numbers.');
                }
            }
        }
        $this->prices = array_replace(self::MODEL_PRICES_PER_MILLION_TOKENS_USD, $overrides);
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?float}
     */
    public function estimate(?string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens): array
    {
        $prices = $this->pricesForModel($model);
        if ($prices === null) {
            return [null, null, null];
        }

        $inputTokens = max(0, $inputTokens);
        $outputTokens = max(0, $outputTokens);
        $cachedInputTokens = max(0, min($cachedInputTokens, $inputTokens));
        $regularInputTokens = $inputTokens - $cachedInputTokens;
        $cachedInputPrice = $prices['cachedInput'] ?? $prices['input'];

        $inputCostUsd = (($regularInputTokens * $prices['input']) + ($cachedInputTokens * $cachedInputPrice)) / 1_000_000;
        $outputCostUsd = ($outputTokens * $prices['output']) / 1_000_000;

        return [$inputCostUsd, $outputCostUsd, $inputCostUsd + $outputCostUsd];
    }

    /**
     * @return array{input: float, cachedInput: float|null, output: float}|null
     */
    private function pricesForModel(?string $model): ?array
    {
        if ($model === null) {
            return null;
        }

        if (isset($this->prices[$model])) {
            return $this->prices[$model];
        }

        // Only dated snapshots inherit a base price, never unknown model families.
        $base = preg_replace('/-\\d{4}-\\d{2}-\\d{2}$/', '', $model);
        return $this->prices[$base] ?? null;
    }
}
