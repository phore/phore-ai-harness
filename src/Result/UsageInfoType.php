<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

use Phore\AiHarness\Usage\CostEstimator;

final readonly class UsageInfoType
{
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

        [$inputCostUsd, $outputCostUsd, $totalCostUsd] = (new CostEstimator())->estimate(
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

}
