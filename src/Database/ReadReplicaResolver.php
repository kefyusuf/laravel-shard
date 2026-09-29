<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Illuminate\Contracts\Config\Repository;

/**
 * Resolves the read connection for a shard: the configured read replica when
 * one is mapped, otherwise the shard connection itself.
 */
class ReadReplicaResolver
{
    public function __construct(protected Repository $config)
    {
    }

    public function resolve(string $shardConnection): string
    {
        $replicas = (array) $this->config->get('redis_sharding.read_replicas.connections', []);

        $replica = $replicas[$shardConnection] ?? null;

        return is_string($replica) && $replica !== '' ? $replica : $shardConnection;
    }
}
