<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Http;

use Illuminate\Http\JsonResponse;
use Laravel\RedisShard\Metrics\ShardHealthReport;

class ShardHealthController
{
    public function __invoke(ShardHealthReport $report): JsonResponse
    {
        $payload = $report->toArray();
        $httpStatus = match ($payload['status']) {
            'ok' => 200,
            'degraded' => 200,
            default => 503,
        };

        return response()->json($payload, $httpStatus);
    }
}
