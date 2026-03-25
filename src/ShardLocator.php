<?php

declare(strict_types=1);

namespace Laravel\RedisShard;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\Connections\Connection;
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
     * @return Connection
     */
    protected function getConnection(): Connection
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
        $shardConnection = $this->getConnection()->command('get', [$redisKey]);

        return is_string($shardConnection) && $shardConnection !== '' ? $shardConnection : null;
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $table, mixed $key, string $shardConnection): bool
    {
        $redisKey = $this->generateRedisKey($table, $key);
        $shardMapKey = $this->generateShardPattern($table, $shardConnection);

        $ttl = (int) $this->config->get('redis_sharding.cache_ttl', 3600);
        $connection = $this->getConnection();

        $connection->command('set', [$redisKey, $shardConnection]);

        if ($ttl > 0) {
            $connection->command('expire', [$redisKey, $ttl]);
        }

        $connection->command('sadd', [$shardMapKey, (string) $key]);

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

        $connection = $this->getConnection();
        $connection->command('del', [$redisKey]);
        $connection->command('srem', [$shardMapKey, (string) $key]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getKeysForShard(string $table, string $shardConnection): array
    {
        $shardMapKey = $this->generateShardPattern($table, $shardConnection);
        $members = $this->getConnection()->command('smembers', [$shardMapKey]);

        return is_array($members) ? $members : [];
    }
}
