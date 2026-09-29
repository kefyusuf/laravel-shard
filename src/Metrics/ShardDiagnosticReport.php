<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics;

use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Support\ModuleRegistry;

/**
 * Full diagnostic snapshot for operators: shards, distribution, locator,
 * modules, and actionable issues. Heavier than {@see ShardHealthReport};
 * intended for dashboards, CI gates, and support dumps.
 */
class ShardDiagnosticReport
{
    public function __construct(
        protected ?ShardLocatorInterface $locator = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $base = (new ShardHealthReport())->toArray();
        $shardNames = ShardManager::getAvailableShards();
        $metadata = $this->safeMetadata();
        $issues = [];

        $shards = [];
        foreach ($shardNames as $name) {
            $meta = $metadata[$name] ?? null;
            $probe = $this->probeShard($name);

            if (! $probe['reachable']) {
                $issues[] = "Shard '{$name}' is unreachable: {$probe['error']}";
            } elseif ($probe['latency_ms'] !== null && $probe['latency_ms'] > 500) {
                $issues[] = sprintf("Shard '%s' is slow (%.1f ms)", $name, $probe['latency_ms']);
            }

            $shards[$name] = [
                'connection' => $name,
                'status' => $probe['reachable'] ? 'up' : 'down',
                'reachable' => $probe['reachable'],
                'driver' => $probe['driver'],
                'latency_ms' => $probe['latency_ms'],
                'error' => $probe['error'],
                'metadata' => [
                    'status' => $meta['status'] ?? 'unknown',
                    'record_count' => $meta['record_count'] ?? 0,
                    'created_at' => $meta['created_at'] ?? null,
                    'last_rebalanced_at' => $meta['last_rebalanced_at'] ?? null,
                ],
            ];
        }

        $distribution = $this->distribution($metadata, $shardNames);
        if ($distribution['balance_score'] !== null && $distribution['balance_score'] < 0.7) {
            $issues[] = sprintf(
                'Data distribution looks skewed (balance score %.2f)',
                $distribution['balance_score']
            );
        }

        $modules = $this->modules();
        if ($modules['redis'] && ! class_exists(\Illuminate\Redis\RedisManager::class)) {
            $issues[] = 'Redis module is enabled but illuminate/redis is not installed';
        }

        $strategy = $this->strategyName();
        if ($strategy === null) {
            $issues[] = 'No default sharding strategy configured';
        }

        return [
            'status' => $issues === [] ? $base['status'] : ($base['status'] === 'down' ? 'down' : 'degraded'),
            'timestamp' => $base['timestamp'],
            'summary' => $base['summary'] + [
                'issues' => count($issues),
                'strategy' => $strategy,
            ],
            'shards' => $shards,
            'distribution' => $distribution,
            'locator' => $this->locatorInfo(),
            'modules' => $modules,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{reachable: bool, latency_ms: float|null, driver: string|null, error: string|null}
     */
    protected function probeShard(string $connection): array
    {
        $start = microtime(true);

        try {
            $pdo = DB::connection($connection)->getPdo();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'reachable' => true,
                'latency_ms' => $latency,
                'driver' => DB::connection($connection)->getDriverName(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'driver' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $metadata
     * @param array<int, string> $shardNames
     * @return array<string, mixed>
     */
    protected function distribution(array $metadata, array $shardNames): array
    {
        $counts = [];
        foreach ($shardNames as $name) {
            $counts[$name] = (int) ($metadata[$name]['record_count'] ?? 0);
        }

        $total = array_sum($counts);
        $max = $counts === [] ? 0 : max($counts);
        $ideal = $shardNames === [] ? 0 : $total / count($shardNames);
        $balance = ($max > 0 && $ideal > 0) ? round($ideal / $max, 3) : null;

        return [
            'record_counts' => $counts,
            'total_records' => $total,
            'max_shard_records' => $max,
            'ideal_per_shard' => round($ideal, 2),
            'balance_score' => $balance,
        ];
    }

    /**
     * @return array{class: string|null, stored_mappings: int|null}
     */
    protected function locatorInfo(): array
    {
        $locator = $this->locator ?? (app()->bound(ShardLocatorInterface::class)
            ? app(ShardLocatorInterface::class)
            : null);

        return [
            'class' => $locator === null ? null : get_class($locator),
            'stored_mappings' => null,
        ];
    }

    /**
     * @return array{core: bool, redis: bool, queue: bool}
     */
    protected function modules(): array
    {
        $registry = app()->bound(ModuleRegistry::class)
            ? app(ModuleRegistry::class)
            : ModuleRegistry::fromConfig();

        return [
            'core' => $registry->enabled(ModuleRegistry::CORE),
            'redis' => $registry->redis(),
            'queue' => $registry->queue(),
        ];
    }

    protected function strategyName(): ?string
    {
        try {
            return ShardManager::strategy()->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function safeMetadata(): array
    {
        try {
            return ShardMetadata::query()->get()
                ->mapWithKeys(static function ($row) {
                    return [$row->name => [
                        'status' => $row->status,
                        'record_count' => (int) $row->record_count,
                        'created_at' => $row->created_at?->toIso8601String(),
                        'last_rebalanced_at' => $row->last_rebalanced_at?->toIso8601String(),
                    ]];
                })
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
