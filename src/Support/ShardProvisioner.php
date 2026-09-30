<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Models\ShardMetadata;

/**
 * Creates new shards: config registration, registry persistence, connection
 * purge and metadata row, with compensating rollback when the metadata write
 * fails. Extracted from ShardManager so routing and provisioning each change
 * for one reason.
 */
class ShardProvisioner
{
    public function __construct(
        protected Repository $config,
        protected DatabaseManager $db
    ) {
    }

    /**
     * Create a new shard.
     *
     * @param string $name
     * @param array<string, mixed> $shardConfig
     * @return bool
     * @throws ShardingException when the metadata row cannot be created
     */
    public function createShard(string $name, array $shardConfig): bool
    {
        $connections = $this->config->get('redis_sharding.connections', []);
        $databaseConnections = $this->config->get('database.connections', []);
        $originalConnections = $connections;
        $originalDatabaseConnections = $databaseConnections;
        $persistedRegistry = ShardRegistry::readAll();

        if (isset($connections[$name]) || isset($databaseConnections[$name])) {
            return false;
        }

        // Add the connection to the config
        $connections[$name] = $shardConfig;
        $this->config->set('redis_sharding.connections', $connections);
        $this->config->set("database.connections.{$name}", $shardConfig);
        ShardRegistry::upsert($name, $shardConfig);

        // Reset cached connection state so the new shard can be used immediately.
        try {
            $this->db->purge($name);
        } catch (\Throwable $e) {
            // Ignore purge failures for brand-new connections.
        }

        try {
            ShardMetadata::query()->create([
                'name' => $name,
                'connection' => $name,
                'created_at' => now(),
                'status' => 'active',
            ]);
        } catch (\Throwable $e) {
            $this->config->set('redis_sharding.connections', $originalConnections);
            $this->config->set('database.connections', $originalDatabaseConnections);
            ShardRegistry::writeAll($persistedRegistry);

            try {
                $this->db->purge($name);
            } catch (\Throwable) {
                // Ignore cleanup failures while unwinding shard creation.
            }

            throw new ShardingException(
                "Failed to create shard '{$name}': {$e->getMessage()}",
                0,
                $e
            );
        }

        return true;
    }
}
