<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics;

use Illuminate\Health\Checks\Check;
use Illuminate\Health\Result;

/**
 * Laravel Health check adapter. Only loaded when the health component exists.
 */
class LaravelShardHealthCheck extends Check
{
    public function run(): Result
    {
        $payload = (new ShardStatusCheck())->run();

        $result = Result::make()
            ->shortSummary($payload['shortSummary'])
            ->meta($payload['meta']);

        return match ($payload['status']) {
            'ok' => $result->ok(),
            'warning' => $result->warn(),
            default => $result->failed(),
        };
    }
}
