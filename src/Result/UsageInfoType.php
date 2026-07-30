<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

final readonly class UsageInfoType
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

    /**
     * @param array<string, mixed> $rawUsage
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $totalTokens = 0,
        public int $cachedInputTokens = 0,
        public int $reasoningOutputTokens = 0,
        public ?string $model = null,
        public ?float $inputCostUsd = null,
        public ?float $outputCostUsd = null,
        public ?float $totalCostUsd = null,
        public array $rawUsage = [],
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromResponseBody(array $body): self
    {
        $usage = isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [];
        $model = isset($body['model']) && is_string($body['model']) ? $body['model'] : null;

        $inputTokens = self::readInt($usage, 'input_tokens');
        $outputTokens = self::readInt($usage, 'output_tokens');
        $totalTokens = self::readInt($usage, 'total_tokens');

        if ($totalTokens === 0 && ($inputTokens > 0 || $outputTokens > 0)) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        $inputDetails = isset($usage['input_tokens_details']) && is_array($usage['input_tokens_details'])
            ? $usage['input_tokens_details']
            : [];
        $outputDetails = isset($usage['output_tokens_details']) && is_array($usage['output_tokens_details'])
            ? $usage['output_tokens_details']
            : [];

        $cachedInputTokens = self::readInt($inputDetails, 'cached_tokens');
        $reasoningOutputTokens = self::readInt($outputDetails, 'reasoning_tokens');

        [$inputCostUsd, $outputCostUsd, $totalCostUsd] = self::calculateCosts(
            $model,
            $inputTokens,
            $cachedInputTokens,
            $outputTokens,
        );

        return new self(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cachedInputTokens: $cachedInputTokens,
            reasoningOutputTokens: $reasoningOutputTokens,
            model: $model,
            inputCostUsd: $inputCostUsd,
            outputCostUsd: $outputCostUsd,
            totalCostUsd: $totalCostUsd,
            rawUsage: $usage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'totalTokens' => $this->totalTokens,
            'cachedInputTokens' => $this->cachedInputTokens,
            'reasoningOutputTokens' => $this->reasoningOutputTokens,
            'model' => $this->model,
            'inputCostUsd' => $this->inputCostUsd,
            'outputCostUsd' => $this->outputCostUsd,
            'totalCostUsd' => $this->totalCostUsd,
        ];
    }

    public function formatTotalCostUsd(int $decimals = 6): ?string
    {
        if ($this->totalCostUsd === null) {
            return null;
        }

        return '$' . number_format($this->totalCostUsd, $decimals, '.', '');
    }

    public function __toString(): string
    {
        $cost = $this->formatTotalCostUsd() ?? 'n/a';

        return sprintf(
            'tokens in=%d out=%d total=%d cost=%s',
            $this->inputTokens,
            $this->outputTokens,
            $this->totalTokens,
            $cost,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readInt(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }

        return 0;
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?float}
     */
    private static function calculateCosts(?string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens): array
    {
        $prices = self::pricesForModel($model);
        if ($prices === null) {
            return [null, null, null];
        }

        $cachedInputTokens = min($cachedInputTokens, $inputTokens);
        $regularInputTokens = $inputTokens - $cachedInputTokens;
        $cachedInputPrice = $prices['cachedInput'] ?? $prices['input'];

        $inputCostUsd = (($regularInputTokens * $prices['input']) + ($cachedInputTokens * $cachedInputPrice)) / 1_000_000;
        $outputCostUsd = ($outputTokens * $prices['output']) / 1_000_000;

        return [$inputCostUsd, $outputCostUsd, $inputCostUsd + $outputCostUsd];
    }

    /**
     * @return array{input: float, cachedInput: float|null, output: float}|null
     */
    private static function pricesForModel(?string $model): ?array
    {
        if ($model === null) {
            return null;
        }

        if (isset(self::MODEL_PRICES_PER_MILLION_TOKENS_USD[$model])) {
            return self::MODEL_PRICES_PER_MILLION_TOKENS_USD[$model];
        }

        $prices = self::MODEL_PRICES_PER_MILLION_TOKENS_USD;
        uksort($prices, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prices as $modelPrefix => $price) {
            if (str_starts_with($model, $modelPrefix . '-') || str_starts_with($model, $modelPrefix . '.')) {
                return $price;
            }
        }

        return null;
    }
}
