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
}
