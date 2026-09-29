<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics;

use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Facades\ShardManager;

/**
 * Lightweight health snapshot safe for HTTP probes and monitoring scrapers.
 *
 * Unlike {@see \Laravel\RedisShard\Monitoring\ShardMonitor}, this report
 * works without Redis and without touching every shard's performance path.
 */
class ShardHealthReport
{
    /**
     * @return array{
     *     status: string,
     *     timestamp: string,
     *     summary: array{total: int, up: int, down: int},
     *     shards: array<string, array{connection: string, status: string, reachable: bool}>
     * }
     */
    public function toArray(): array
    {
        $shardNames = ShardManager::getAvailableShards();
        $shards = [];
        $up = 0;
        $down = 0;

        foreach ($shardNames as $name) {
            $reachable = $this->isReachable($name);
            $reachable ? $up++ : $down++;

            $shards[$name] = [
                'connection' => $name,
                'status' => $reachable ? 'up' : 'down',
                'reachable' => $reachable,
            ];
        }

        $status = match (true) {
            $down === 0 && $up > 0 => 'ok',
            $up > 0 => 'degraded',
            default => 'down',
        };

        return [
            'status' => $status,
            'timestamp' => now()->toISOString(),
            'summary' => [
                'total' => count($shardNames),
                'up' => $up,
                'down' => $down,
            ],
            'shards' => $shards,
        ];
    }

    public function status(): string
    {
        return (string) $this->toArray()['status'];
    }

    protected function isReachable(string $connection): bool
    {
        try {
            DB::connection($connection)->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
