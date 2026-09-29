<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics;

/**
 * Laravel Health check for shard connectivity.
 *
 * Registered automatically when `Illuminate\Support\Facades\Health` is
 * available (Laravel 11+ health component). Safe to leave registered on
 * apps that never install/enable the health dashboard.
 */
class ShardStatusCheck
{
    public function name(): string
    {
        return 'Shard status';
    }

    /**
     * @return array{status: string, shortSummary: string, meta: array<string, mixed>}
     */
    public function run(): array
    {
        $report = (new ShardHealthReport())->toArray();

        return [
            'status' => $report['status'] === 'ok' ? 'ok' : ($report['status'] === 'degraded' ? 'warning' : 'failed'),
            'shortSummary' => sprintf(
                '%d/%d shards up',
                $report['summary']['up'],
                $report['summary']['total']
            ),
            'meta' => [
                'shards' => $report['shards'],
                'summary' => $report['summary'],
            ],
        ];
    }
}
