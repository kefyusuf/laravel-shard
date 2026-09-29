<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Contracts;

interface ShardLocatorInterface
{
    /**
     * Locate the shard for a given key.
     *
     * @param string $table The table name
     * @param mixed $key The primary key or shard key
     * @return string|null The shard connection name or null if not found
     */
    public function locate(string $table, mixed $key): ?string;

    /**
     * Register a key to a specific shard.
     *
     * @param string $table The table name
     * @param mixed $key The primary key or shard key
     * @param string $shardConnection The shard connection name
     * @return bool Success status
     */
    public function register(string $table, mixed $key, string $shardConnection): bool;

    /**
     * Remove a key from the shard registry.
     *
     * @param string $table The table name
     * @param mixed $key The primary key or shard key
     * @return bool Success status
     */
    public function forget(string $table, mixed $key): bool;

    /**
     * Get all keys for a specific table and shard.
     *
     * @param string $table The table name
     * @param string $shardConnection The shard connection name
     * @return array<mixed> Array of keys
     */
    public function getKeysForShard(string $table, string $shardConnection): array;
}
