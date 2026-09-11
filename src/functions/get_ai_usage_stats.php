<?php

declare(strict_types=1);

use Phore\AiHarness\Usage\CostEstimator;
use Phore\AiHarness\Usage\GlobalUsage;

/** All client request attempts in this PHP runtime, independent of debug logging. */
function get_ai_usage_stats(?CostEstimator $estimator = null): array
{
    return GlobalUsage::instance()->getStats($estimator);
}
