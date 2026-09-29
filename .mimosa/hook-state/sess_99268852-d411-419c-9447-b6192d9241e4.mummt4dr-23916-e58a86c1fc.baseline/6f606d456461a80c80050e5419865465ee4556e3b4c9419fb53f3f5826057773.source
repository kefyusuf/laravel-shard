<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\RedisShard\Metrics\ShardDiagnosticReport;
use Laravel\RedisShard\Metrics\ShardHealthReport;

class ShardHealthController
{
    public function __invoke(Request $request, ShardHealthReport $light, ShardDiagnosticReport $detailed): JsonResponse
    {
        $payload = $request->boolean('detail') || $request->boolean('verbose')
            ? $detailed->toArray()
            : $light->toArray();

        $httpStatus = match ($payload['status']) {
            'ok' => 200,
            'degraded' => 200,
            default => 503,
        };

        return response()->json($payload, $httpStatus);
    }
}
