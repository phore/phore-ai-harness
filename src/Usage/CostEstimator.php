<?php

declare(strict_types=1);

namespace Phore\AiHarness\Usage;

use InvalidArgumentException;

/**
 * Approximate text-token prices, USD per million tokens (checked 2026-09-11).
 * Sources: https://developers.openai.com/api/docs/pricing and model pages.
 * GPT-6/5.6 and GPT-5.5 use published long-context standard rates conservatively.
 * Other exact entries use standard model-page rates. See README for the policy.
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
        'gpt-6-astra' => ['input' => 20.000, 'cachedInput' => 2.000, 'output' => 75.00],
        'gpt-5.6-sol' => ['input' => 8.000, 'cachedInput' => 0.800, 'output' => 30.00],
        'gpt-5.6-terra' => ['input' => 4.000, 'cachedInput' => 0.400, 'output' => 18.00],
        'gpt-5.6-luna' => ['input' => 0.400, 'cachedInput' => 0.040, 'output' => 1.80],
        'gpt-5.6-cyber' => ['input' => 12.500, 'cachedInput' => 1.250, 'output' => 75.00],
        'gpt-5.5' => ['input' => 10.000, 'cachedInput' => 1.000, 'output' => 45.00],
        'gpt-5.5-pro' => ['input' => 30.000, 'cachedInput' => null, 'output' => 180.00],
        'gpt-5.4' => ['input' => 2.500, 'cachedInput' => 0.250, 'output' => 15.00],
        'gpt-5.4-mini' => ['input' => 0.750, 'cachedInput' => 0.075, 'output' => 4.50],
        'gpt-5.4-nano' => ['input' => 0.200, 'cachedInput' => 0.020, 'output' => 1.25],
        'gpt-5.3-codex' => ['input' => 1.750, 'cachedInput' => 0.175, 'output' => 14.00],
        'gpt-5.2' => ['input' => 1.750, 'cachedInput' => 0.175, 'output' => 14.00],
        'gpt-4.1' => ['input' => 2.000, 'cachedInput' => 0.500, 'output' => 8.00],
        'chat-latest' => ['input' => 5.000, 'cachedInput' => 0.500, 'output' => 30.00],
        'gpt-5' => ['input' => 1.25, 'cachedInput' => 0.125, 'output' => 10.00],
        'gpt-5-mini' => ['input' => 0.25, 'cachedInput' => 0.025, 'output' => 2.00],
        'gpt-5-nano' => ['input' => 0.05, 'cachedInput' => 0.005, 'output' => 0.40],
    ];

    // Upper envelope: GPT-6 Astra long-context Fast input; GPT-5.5 Pro output.
    // Fallbacks deliberately assume no cached-input discount.
    private const HIGH_PRICE_FLOOR = ['input' => 40.0, 'cachedInput' => null, 'output' => 180.0];
    private const MINI_PATTERN = '~(?:^|[-_./:])mini(?:$|[-_./:])~i';
    private const NANO_PATTERN = '~(?:^|[-_./:])nano(?:$|[-_./:])~i';
    private const HIGH_PATTERN = '~(?:^|[-_./:])(?:pro|max|ultra|opus|astra)(?:$|[-_./:])~i';

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
        $prices = $this->getPriceInfo($model)['prices'];
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
     * Exact overrides and dated snapshots take precedence over regex estimates.
     * @return array{source: string, prices: array{input: float, cachedInput: float|null, output: float}|null}
     */
    public function getPriceInfo(?string $model): array
    {
        if ($model === null || trim($model) === '') {
            return ['source' => 'unknown', 'prices' => null];
        }
        $model = trim($model);
        if (isset($this->prices[$model])) {
            return ['source' => 'exact', 'prices' => $this->prices[$model]];
        }
        $model = strtolower($model);
        if (isset($this->prices[$model])) {
            return ['source' => 'exact', 'prices' => $this->prices[$model]];
        }
        $base = preg_replace('/-\\d{4}-\\d{2}-\\d{2}$/', '', $model);
        if (isset($this->prices[$base])) {
            return ['source' => 'snapshot', 'prices' => $this->prices[$base]];
        }
        // Premium qualifiers win over size qualifiers (e.g. future-mini-pro).
        if (!preg_match(self::HIGH_PATTERN, $model)) {
            foreach (['mini' => self::MINI_PATTERN, 'nano' => self::NANO_PATTERN] as $family => $pattern) {
                if (preg_match($pattern, $model)) {
                    return ['source' => $family . '-fallback', 'prices' => $this->maximumPrices($pattern)];
                }
            }
        }
        return ['source' => 'highest-fallback', 'prices' => $this->maximumPrices()];
    }

    /** Component-wise maxima; overrides can raise but never lower fallback floors. */
    private function maximumPrices(?string $pattern = null): array
    {
        $rates = $pattern === null ? self::HIGH_PRICE_FLOOR
            : ['input' => 0.0, 'cachedInput' => null, 'output' => 0.0];
        foreach ([self::MODEL_PRICES_PER_MILLION_TOKENS_USD, $this->prices] as $table) {
            foreach ($table as $name => $price) {
                if ($pattern !== null && (!preg_match($pattern, $name) || preg_match(self::HIGH_PATTERN, $name))) {
                    continue;
                }
                $rates['input'] = max($rates['input'], $price['input'], $price['cachedInput'] ?? 0.0);
                $rates['output'] = max($rates['output'], $price['output']);
            }
        }
        return $rates;
    }
}
