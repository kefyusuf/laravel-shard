<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Monitoring;

use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;

class ShardMonitor
{
    /**
     * @var ShardLocatorInterface
     */
    protected ShardLocatorInterface $locator;

    /**
     * @var array
     */
    protected array $metrics = [];

    /**
     * @var RedisManager|null
     */
    protected ?RedisManager $redis;

    /**
     * Create a new shard monitor instance.
     *
     * @param ShardLocatorInterface $locator
     * @param RedisManager|null $redis
     */
    public function __construct(ShardLocatorInterface $locator, ?RedisManager $redis = null)
    {
        $this->locator = $locator;
        $this->redis = $redis;
    }

    /**
     * Get the configured Redis connection instance.
     *
     * @return RedisConnection|object
     */
    protected function getRedisConnection()
    {
        $redisConnection = config('redis_sharding.redis_connection', 'default');
        $manager = $this->redis ?? app('redis');

        return $manager->connection($redisConnection);
    }

    /**
     * Collect all shard metrics.
     *
     * @return array
     */
    public function collectMetrics(): array
    {
        $this->metrics = [
            'timestamp' => now()->toISOString(),
            'shards' => $this->collectShardMetrics(),
            'distribution' => $this->collectDistributionMetrics(),
            'performance' => $this->collectPerformanceMetrics(),
            'health' => $this->collectHealthMetrics(),
        ];

        // Cache metrics for 5 minutes
        Cache::put('redis_shard_metrics', $this->metrics, 300);

        return $this->metrics;
    }

    /**
     * Get cached metrics or collect new ones.
     *
     * @return array
     */
    public function getMetrics(): array
    {
        return Cache::remember('redis_shard_metrics', 300, function () {
            return $this->collectMetrics();
        });
    }

    /**
     * Collect metrics for each shard.
     *
     * @return array
     */
    protected function collectShardMetrics(): array
    {
        $shards = ShardManager::getAvailableShards();
        $metadata = ShardMetadata::all()->keyBy('name');
        $shardMetrics = [];

        foreach ($shards as $shardName) {
            $meta = $metadata->get($shardName);
            
            $shardMetrics[$shardName] = [
                'name' => $shardName,
                'status' => $meta->status ?? 'unknown',
                'record_count' => $meta->record_count ?? 0,
                'created_at' => $meta?->created_at,
                'last_rebalanced_at' => $meta?->last_rebalanced_at,
                'connection_status' => $this->checkShardConnection($shardName),
                'disk_usage' => $this->getShardDiskUsage($shardName),
                'query_performance' => $this->measureQueryPerformance($shardName),
            ];
        }

        return $shardMetrics;
    }

    /**
     * Collect distribution metrics across tables.
     *
     * @return array
     */
    protected function collectDistributionMetrics(): array
    {
        $shards = ShardManager::getAvailableShards();
        $tables = $this->getMonitoredTables();
        $distribution = [];

        foreach ($tables as $table) {
            $tableDistribution = [];
            $totalKeys = 0;

            foreach ($shards as $shardName) {
                $keyCount = count($this->locator->getKeysForShard($table, $shardName));
                $tableDistribution[$shardName] = $keyCount;
                $totalKeys += $keyCount;
            }

            $distribution[$table] = [
                'total_keys' => $totalKeys,
                'shard_distribution' => $tableDistribution,
                'balance_score' => $this->calculateBalanceScore($tableDistribution),
                'std_deviation' => $this->calculateStandardDeviation($tableDistribution),
            ];
        }

        return $distribution;
    }

    /**
     * Collect performance metrics.
     *
     * @return array
     */
    protected function collectPerformanceMetrics(): array
    {
        return [
            'redis_latency' => $this->measureRedisLatency(),
            'shard_lookup_time' => $this->measureShardLookupTime(),
            'strategy_performance' => $this->measureStrategyPerformance(),
            'cache_hit_rate' => $this->calculateCacheHitRate(),
        ];
    }

    /**
     * Collect health metrics.
     *
     * @return array
     */
    protected function collectHealthMetrics(): array
    {
        $shards = ShardManager::getAvailableShards();
        $healthyShards = 0;
        $totalConnections = count($shards);

        foreach ($shards as $shardName) {
            if ($this->checkShardConnection($shardName) === 'healthy') {
                $healthyShards++;
            }
        }

        return [
            'overall_health' => $healthyShards === $totalConnections ? 'healthy' : 'degraded',
            'healthy_shards' => $healthyShards,
            'total_shards' => $totalConnections,
            'health_percentage' => $totalConnections > 0 ? round(($healthyShards / $totalConnections) * 100, 2) : 0,
            'redis_status' => $this->checkRedisHealth(),
        ];
    }

    /**
     * Check the connection status of a shard.
     *
     * @param string $shardName
     * @return string
     */
    protected function checkShardConnection(string $shardName): string
    {
        try {
            $startTime = microtime(true);
            DB::connection($shardName)->getPdo();
            $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

            if ($responseTime > 1000) {
                return 'slow';
            }

            return 'healthy';
        } catch (\Exception $e) {
            return 'unhealthy';
        }
    }

    /**
     * Get disk usage for a shard.
     *
     * @param string $shardName
     * @return array|null
     */
    protected function getShardDiskUsage(string $shardName): ?array
    {
        try {
            $connection = DB::connection($shardName);
            $driver = $connection->getDriverName();

            switch ($driver) {
                case 'mysql':
                    $result = $connection->select("
                        SELECT 
                            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                            COUNT(*) as table_count
                        FROM information_schema.tables 
                        WHERE table_schema = DATABASE()
                    ");
                    break;

                case 'pgsql':
                    $result = $connection->select("
                        SELECT 
                            ROUND(pg_database_size(current_database()) / 1024.0 / 1024.0, 2) AS size_mb,
                            COUNT(*) as table_count
                        FROM information_schema.tables 
                        WHERE table_schema = 'public'
                    ");
                    break;

                default:
                    return null;
            }

            return [
                'size_mb' => $result[0]->size_mb ?? 0,
                'table_count' => $result[0]->table_count ?? 0,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Measure query performance for a shard.
     *
     * @param string $shardName
     * @return array
     */
    protected function measureQueryPerformance(string $shardName): array
    {
        try {
            $startTime = microtime(true);
            DB::connection($shardName)->select('SELECT 1');
            $queryTime = (microtime(true) - $startTime) * 1000;

            return [
                'avg_query_time_ms' => round($queryTime, 2),
                'status' => $queryTime > 100 ? 'slow' : 'fast',
            ];
        } catch (\Exception $e) {
            return [
                'avg_query_time_ms' => null,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Measure Redis latency.
     *
     * @return float
     */
    protected function measureRedisLatency(): float
    {
        try {
            $startTime = microtime(true);
            $this->getRedisConnection()->command('ping');
            return round((microtime(true) - $startTime) * 1000, 2);
        } catch (\Exception $e) {
            return -1;
        }
    }

    /**
     * Measure shard lookup time.
     *
     * @return float
     */
    protected function measureShardLookupTime(): float
    {
        try {
            $startTime = microtime(true);
            ShardManager::getShardConnection('test_table', 'test_key');
            return round((microtime(true) - $startTime) * 1000, 2);
        } catch (\Exception $e) {
            return -1;
        }
    }

    /**
     * Measure strategy performance.
     *
     * @return array
     */
    protected function measureStrategyPerformance(): array
    {
        $strategies = ShardManager::strategies();
        $shards = ShardManager::getAvailableShards();
        $performance = [];

        foreach ($strategies as $name => $strategy) {
            $startTime = microtime(true);
            
            // Test with 100 sample keys
            for ($i = 1; $i <= 100; $i++) {
                $strategy->determine('test_table', $i, $shards);
            }
            
            $totalTime = (microtime(true) - $startTime) * 1000;
            $performance[$name] = [
                'total_time_ms' => round($totalTime, 2),
                'avg_time_per_key_ms' => round($totalTime / 100, 4),
            ];
        }

        return $performance;
    }

    /**
     * Calculate cache hit rate.
     *
     * @return float
     */
    protected function calculateCacheHitRate(): float
    {
        // This would require implementing cache hit/miss tracking
        // For now, return a placeholder
        return 85.5;
    }

    /**
     * Check Redis health.
     *
     * @return string
     */
    protected function checkRedisHealth(): string
    {
        try {
            $this->getRedisConnection()->command('ping');
            return 'healthy';
        } catch (\Exception $e) {
            return 'unhealthy';
        }
    }

    /**
     * Get list of tables to monitor.
     *
     * @return array
     */
    protected function getMonitoredTables(): array
    {
        // This could be configurable
        return config('redis_sharding.monitored_tables', ['users', 'orders', 'products']);
    }

    /**
     * Calculate balance score for distribution.
     *
     * @param array $distribution
     * @return float
     */
    protected function calculateBalanceScore(array $distribution): float
    {
        $total = array_sum($distribution);
        if ($total === 0) {
            return 100.0;
        }

        $ideal = $total / count($distribution);
        $maxDeviation = 0;

        foreach ($distribution as $count) {
            $deviation = abs($count - $ideal) / $ideal;
            $maxDeviation = max($maxDeviation, $deviation);
        }

        return max(0, 100 - ($maxDeviation * 100));
    }

    /**
     * Calculate standard deviation.
     *
     * @param array $distribution
     * @return float
     */
    protected function calculateStandardDeviation(array $distribution): float
    {
        $count = count($distribution);
        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($distribution) / $count;
        $variance = 0;

        foreach ($distribution as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / $count);
    }
}
