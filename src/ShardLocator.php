<?php

declare(strict_types=1);

namespace Laravel\RedisShard;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\RedisManager;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;

class ShardLocator implements ShardLocatorInterface
{
    /**
     * Create a new ShardLocator instance.
     *
     * @param RedisManager $redis
     * @param Repository $config
     */
    public function __construct(
        protected RedisManager $redis,
        protected Repository $config
    ) {
    }

    /**
     * Get the Redis connection.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function getConnection()
    {
        $connection = $this->config->get('redis_sharding.redis_connection', 'default');
        return $this->redis->connection($connection);
    }

    /**
     * Generate a Redis key for a table and primary key.
     *
     * @param string $table
     * @param mixed $key
     * @return string
     */
    protected function generateRedisKey(string $table, mixed $key): string
    {
        return "shard:{$table}:{$key}";
    }

    /**
     * Generate a Redis key pattern for a table.
     *
     * @param string $table
     * @return string
     */
    protected function generateTablePattern(string $table): string
    {
        return "shard:{$table}:*";
    }

    /**
     * Generate a Redis key pattern for a table and shard.
     *
     * @param string $table
     * @param string $shardConnection
     * @return string
     */
    protected function generateShardPattern(string $table, string $shardConnection): string
    {
        return "shard_map:{$table}:{$shardConnection}";
    }

    /**
     * {@inheritdoc}
     */
    public function locate(string $table, mixed $key): ?string
    {
        $redisKey = $this->generateRedisKey($table, $key);
        $shardConnection = $this->getConnection()->get($redisKey);
        
        return $shardConnection;
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $table, mixed $key, string $shardConnection): bool
    {
        $redisKey = $this->generateRedisKey($table, $key);
        $shardMapKey = $this->generateShardPattern($table, $shardConnection);
        
        $ttl = $this->config->get('redis_sharding.cache_ttl', 3600);
        
        $this->getConnection()->pipeline(function ($pipeline) use ($redisKey, $shardConnection, $ttl, $shardMapKey, $key): void {
            // Store the shard connection for this key
            $pipeline->set($redisKey, $shardConnection);

            if ($ttl > 0) {
                $pipeline->expire($redisKey, $ttl);
            }

            // Add this key to the set of keys for this shard
            $pipeline->sadd($shardMapKey, (string) $key);
        });
        
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $table, mixed $key): bool
    {
        $redisKey = $this->generateRedisKey($table, $key);
        $shardConnection = $this->locate($table, $key);
        
        if ($shardConnection === null) {
            return false;
        }
        
        $shardMapKey = $this->generateShardPattern($table, $shardConnection);
        
        $this->getConnection()->pipeline(function ($pipeline) use ($redisKey, $shardMapKey, $key): void {
            $pipeline->del($redisKey);
            $pipeline->srem($shardMapKey, (string) $key);
        });
        
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getKeysForShard(string $table, string $shardConnection): array
    {
        $shardMapKey = $this->generateShardPattern($table, $shardConnection);
        return $this->getConnection()->smembers($shardMapKey);
    }
}
