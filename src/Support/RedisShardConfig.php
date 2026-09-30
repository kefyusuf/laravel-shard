<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Read-only accessor for the redis_sharding configuration keys the package
 * reads at runtime. One home per key so default values and validation live
 * in a single place instead of being hand-copied across commands, strategies
 * and the service provider.
 */
class RedisShardConfig
{
    public function __construct(protected Repository $config)
    {
    }

    /**
     * The fixed virtual-bucket count (never below one).
     */
    public function bucketCount(): int
    {
        return max(1, (int) $this->config->get('redis_sharding.virtual_buckets.count', 1024));
    }

    /**
     * The configured multi-tenancy bridge driver ('' = disabled).
     */
    public function tenancyDriver(): string
    {
        return (string) $this->config->get('redis_sharding.tenancy.driver', '');
    }

    /**
     * The table whose shard key is the tenant id.
     */
    public function tenancyTable(): string
    {
        return (string) $this->config->get('redis_sharding.tenancy.table', 'tenants');
    }

    /**
     * The Redis connection used for the persistent shard map.
     */
    public function redisConnection(): string
    {
        return (string) $this->config->get('redis_sharding.redis_connection', 'default');
    }

    /**
     * Tables tracked by health/status/monitoring commands.
     *
     * @return list<string>
     */
    public function monitoredTables(): array
    {
        $tables = $this->config->get('redis_sharding.monitored_tables', ['users', 'orders', 'products']);

        return is_array($tables) ? array_values($tables) : [];
    }

    /**
     * The configured shard connection names.
     *
     * @return list<string>
     */
    public function shardNames(): array
    {
        return array_keys($this->shardConnections());
    }

    /**
     * The full shard connection definition map.
     *
     * @return array<string, array<string, mixed>>
     */
    public function shardConnections(): array
    {
        $connections = $this->config->get('redis_sharding.connections', []);

        return is_array($connections) ? $connections : [];
    }

    /**
     * The application's default database connection.
     */
    public function defaultConnection(): string
    {
        return (string) $this->config->get('database.default');
    }

    /**
     * @return array<string, string> table => key column
     */
    public function rebalanceTableKeyColumns(): array
    {
        $columns = $this->config->get('redis_sharding.rebalance.table_key_columns', []);

        return is_array($columns) ? $columns : [];
    }

    public function rebalanceDeleteSourceAfterCopy(): bool
    {
        return (bool) $this->config->get('redis_sharding.rebalance.delete_source_after_copy', true);
    }

    public function rebalanceFenceEnabled(): bool
    {
        return (bool) $this->config->get('redis_sharding.rebalance.fence_enabled', true);
    }

    /**
     * Where the shard registry document lives.
     */
    public function registryPath(): string
    {
        $configuredPath = $this->config->get('redis_sharding.registry_path');
        if (is_string($configuredPath) && $configuredPath !== '') {
            return $configuredPath;
        }

        if (function_exists('storage_path')) {
            return storage_path('app/redis_sharding_registry.json');
        }

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'redis_sharding_registry.json';
    }

    /**
     * The configured shard metadata table name (null = model default).
     */
    public function metadataTable(): ?string
    {
        $table = $this->config->get('redis_sharding.metadata_table');

        return is_string($table) && $table !== '' ? $table : null;
    }
}
