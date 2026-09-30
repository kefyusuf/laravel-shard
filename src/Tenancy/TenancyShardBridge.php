<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tenancy;

use Laravel\RedisShard\Facades\ShardManager;

/**
 * Bridges multi-tenancy packages to shard routing: the tenant id is used
 * as the shard key, the resolved shard connection is published on the
 * request under the same `shard_connection` attribute the shard middleware
 * uses, so Shardable models route there for the rest of the request.
 */
class TenancyShardBridge
{
    protected ?string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? app(\Laravel\RedisShard\Support\RedisShardConfig::class)->tenancyTable();
    }

    /**
     * Resolve the shard connection for a tenant id and pin it to the request.
     *
     * @param mixed $tenantId
     * @return string|null the resolved shard connection, or null when no tenant is present
     */
    public function makeCurrent(mixed $tenantId): ?string
    {
        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        $connection = ShardManager::getShardConnection($this->table, $tenantId);

        app(\Laravel\RedisShard\Support\RequestShardContext::class)->set($connection);

        return $connection;
    }
}
