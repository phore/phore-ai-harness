<?php

declare(strict_types=1);

namespace Phore\AiHarness\Usage;

use Phore\AiHarness\Result\UsageInfoType;

/** Process-local counters; stores aggregates only, never prompts or responses. */
final class GlobalUsage
{
    private static ?self $instance = null;
    private static ?object $mainScopeKey = null;
    /** @var \WeakMap<object, object>|null */
    private static ?\WeakMap $scopes = null;
    private array $models = [];

    /**
     * Attribute requests started inside this operation to this independent tracker.
     *
     * The process-wide aggregate is still updated. Nested scopes and independent
     * Fibers are isolated. Each request keeps its original scope through finish(),
     * even if completed outside that scope (e.g. spooler cleanup).
     * The optional observer receives a detached snapshot after begin and finish.
     * Use a fresh tracker for each independent edit; no global reset is needed.
     *
     * @param callable(): mixed $operation Operation whose requests are measured.
     * @param callable(array): void|null $onUpdate Usage observer.
     * @return mixed Result of the operation.
     * @throws \LogicException If this is the process-global tracker.
     * @example $usage = new GlobalUsage(); $result = $usage->track(fn () => $context->text('Edit'), fn (array $stats) => reportUsage($stats));
     * @see getStats()
     */
    public function track(callable $operation, ?callable $onUpdate = null): mixed
    {
        if ($this === self::instance()) {
            throw new \LogicException('Use an independent usage tracker for a scope.');
        }
        $key = self::scopeKey();
        $scopes = self::$scopes ??= new \WeakMap();
        $previous = $scopes[$key] ?? null;
        $scopes[$key] = (object) ['tracker' => $this, 'observer' => $onUpdate];
        try {
            return $operation();
        } finally {
            if ($previous === null) {
                unset($scopes[$key]);
            } else {
                $scopes[$key] = $previous;
            }
        }
    }

    private static function scopeKey(): object
    {
        return \Fiber::getCurrent() ?? (self::$mainScopeKey ??= new \stdClass());
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @internal Start one client request attempt, including setup failures. */
    public function begin(string $model): object
    {
        $call = $this->beginLocal($model);
        if ($this === self::instance()) {
            $scope = self::$scopes[self::scopeKey()] ?? null;
            if ($scope !== null) {
                $call->scope = $scope;
                $call->scopeCall = $scope->tracker->beginLocal($model);
                $this->notifyScope($scope);
            }
        }

        return $call;
    }

    private function beginLocal(string $model): object
    {
        $this->models[$model] ??= self::emptyCounters();
        $this->models[$model]['requests']++;
        $this->models[$model]['pendingRequests']++;

        return (object) ['model' => $model, 'finished' => false];
    }

    /** @internal Complete once, even when stream or spooler cleanup runs twice. */
    public function finish(object $call, ?array $body = null, bool $failed = false): void
    {
        if ($call->finished) {
            return;
        }
        $this->finishLocal($call, $body, $failed);
        if (isset($call->scope)) {
            $scope = $call->scope;
            $scope->tracker->finishLocal($call->scopeCall, $body, $failed);
            $this->notifyScope($scope);
        }
    }

    private function notifyScope(object $scope): void
    {
        if ($scope->observer !== null) {
            ($scope->observer)($scope->tracker->getStats());
        }
    }

    private function finishLocal(object $call, ?array $body, bool $failed): void
    {
        $call->finished = true;
        $requestedModel = $call->model;
        $model = is_string($body['model'] ?? null) && $body['model'] !== ''
            ? $body['model'] : $requestedModel;
        $this->models[$requestedModel]['pendingRequests']--;
        if ($model !== $requestedModel) {
            $this->models[$requestedModel]['requests']--;
            if ($this->models[$requestedModel]['requests'] === 0) {
                unset($this->models[$requestedModel]);
            }
            $this->models[$model] ??= self::emptyCounters();
            $this->models[$model]['requests']++;
        }
        $stats = &$this->models[$model];
        $failed = $failed || in_array($body['status'] ?? null, ['failed', 'incomplete', 'cancelled'], true)
            || isset($body['error']);
        $stats['errors'] += (int) $failed;
        $raw = $body['usage'] ?? null;
        if (!is_array($raw) || !isset($raw['input_tokens'], $raw['output_tokens'])
            || !is_numeric($raw['input_tokens']) || !is_numeric($raw['output_tokens'])) {
            $stats['missingUsageRequests']++;
        }
        $usage = UsageInfoType::fromResponseBody($body ?? []);
        foreach (['inputTokens', 'outputTokens', 'totalTokens', 'reasoningOutputTokens'] as $key) {
            $stats[$key] += max(0, $usage->$key);
        }
        $stats['cachedInputTokens'] += max(0, min($usage->cachedInputTokens, $usage->inputTokens));
    }

    /**
     * A detached snapshot. knownCostUsd is the priced subtotal; totalCostUsd is
     * null if any request is pending, lacks usage, or has no known model price.
     */
    public function getStats(?CostEstimator $estimator = null): array
    {
        $estimator ??= new CostEstimator();
        $total = self::emptyCounters();
        $total['unpricedRequests'] = 0;
        $total['fallbackRequests'] = 0;
        $total['knownCostUsd'] = 0.0;
        $models = [];
        foreach ($this->models as $model => $counters) {
            [, , $cost] = $estimator->estimate(
                (string) $model, $counters['inputTokens'], $counters['cachedInputTokens'], $counters['outputTokens'],
            );
            $row = $counters;
            $priceInfo = $estimator->getPriceInfo((string) $model);
            $row['pricingSource'] = $priceInfo['source'];
            $row['pricesPerMillionTokensUsd'] = $priceInfo['prices'];
            $row['fallbackRequests'] = str_ends_with($priceInfo['source'], '-fallback')
                ? $counters['requests'] - $counters['pendingRequests'] : 0;
            $row['unpricedRequests'] = $cost === null ? $counters['requests'] - $counters['pendingRequests'] : 0;
            $row['knownCostUsd'] = $cost ?? 0.0;
            $row['totalCostUsd'] = $row['missingUsageRequests'] + $row['pendingRequests'] + $row['unpricedRequests'] === 0
                ? $row['knownCostUsd'] : null;
            $models[$model] = $row;
            foreach (array_keys($total) as $key) {
                $total[$key] += $row[$key];
            }
        }
        $total['totalCostUsd'] = $total['missingUsageRequests'] + $total['pendingRequests'] + $total['unpricedRequests'] === 0
            ? $total['knownCostUsd'] : null;
        return [...$total, 'currency' => 'USD', 'estimated' => true, 'models' => $models];
    }

    private static function emptyCounters(): array
    {
        return [
            'requests' => 0, 'errors' => 0, 'pendingRequests' => 0,
            'missingUsageRequests' => 0, 'inputTokens' => 0, 'outputTokens' => 0,
            'totalTokens' => 0, 'cachedInputTokens' => 0, 'reasoningOutputTokens' => 0,
        ];
    }
}
