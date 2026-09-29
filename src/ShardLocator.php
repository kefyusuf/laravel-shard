<?php

declare(strict_types=1);

namespace Laravel\RedisShard;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Contracts\ShardStateResettable;
use Laravel\RedisShard\Exceptions\ShardingException;

class ShardLocator implements ShardLocatorInterface, ShardStateResettable
{
    /**
     * @var array<string, string>
     */
    protected array $localCache = [];

    /**
     * @var array<string, array<string, true>>
     */
    protected array $localShardKeys = [];

    protected ?float $circuitOpenUntil = null;

    /**
     * Create a new ShardLocator instance.
     *
     * @param RedisManager $redis
     * @param Repository $config
     */
    public function __construct(
        protected RedisManager $redis,
        protected Repository $config,
        protected ?CacheFactory $cacheFactory = null
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

    protected function generateLocalCacheKey(string $table, mixed $key): string
    {
        return $table . ':' . (string) $key;
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

    protected function getFallbackStore(): ?CacheRepository
    {
        if ($this->cacheFactory === null) {
            return null;
        }

        $store = $this->config->get('redis_sharding.locator.fallback_store');

        if (! is_string($store) || $store === '') {
            return null;
        }

        return $this->cacheFactory->store($store);
    }

    protected function generateFallbackMappingKey(string $table, mixed $key): string
    {
        return 'redis_shard_fallback:' . $this->generateRedisKey($table, $key);
    }

    protected function generateFallbackShardSetKey(string $table, string $shardConnection): string
    {
        return 'redis_shard_fallback:' . $this->generateShardPattern($table, $shardConnection);
    }

    protected function isCircuitOpen(): bool
    {
        return $this->circuitOpenUntil !== null && microtime(true) < $this->circuitOpenUntil;
    }

    protected function markRedisFailure(): void
    {
        $duration = max(1, (int) $this->config->get('redis_sharding.locator.circuit_breaker_seconds', 5));
        $this->circuitOpenUntil = microtime(true) + $duration;
    }

    protected function resetCircuit(): void
    {
        $this->circuitOpenUntil = null;
    }

    /**
     * {@inheritdoc}
     */
    public function resetState(): void
    {
        $this->localCache = [];
        $this->localShardKeys = [];
        $this->circuitOpenUntil = null;
    }

    protected function getLocalCacheLimit(): int
    {
        return max(1, (int) $this->config->get('redis_sharding.locator.local_cache_limit', 10000));
    }

    protected function rememberLocalMapping(string $table, mixed $key, string $shardConnection): void
    {
        $localCacheKey = $this->generateLocalCacheKey($table, $key);

        if (isset($this->localCache[$localCacheKey])) {
            $previousShard = $this->localCache[$localCacheKey];

            if ($previousShard !== $shardConnection) {
                unset($this->localShardKeys[$this->generateShardPattern($table, $previousShard)][(string) $key]);
            }

            unset($this->localCache[$localCacheKey]);
        }

        $this->localCache[$localCacheKey] = $shardConnection;
        $this->localShardKeys[$this->generateShardPattern($table, $shardConnection)][(string) $key] = true;

        while (count($this->localCache) > $this->getLocalCacheLimit()) {
            $evictedCacheKey = array_key_first($this->localCache);

            if ($evictedCacheKey === null) {
                break;
            }

            $evictedShard = $this->localCache[$evictedCacheKey];
            unset($this->localCache[$evictedCacheKey]);

            $parts = explode(':', $evictedCacheKey, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$evictedTable, $evictedKey] = $parts;
            unset($this->localShardKeys[$this->generateShardPattern($evictedTable, $evictedShard)][$evictedKey]);
        }
    }

    protected function forgetLocalMapping(string $table, mixed $key): ?string
    {
        $localCacheKey = $this->generateLocalCacheKey($table, $key);
        $shardConnection = $this->localCache[$localCacheKey] ?? null;

        if ($shardConnection === null) {
            return null;
        }

        unset($this->localCache[$localCacheKey]);
        unset($this->localShardKeys[$this->generateShardPattern($table, $shardConnection)][(string) $key]);

        return $shardConnection;
    }

    protected function locateInLocalCache(string $table, mixed $key): ?string
    {
        $localCacheKey = $this->generateLocalCacheKey($table, $key);
        $shardConnection = $this->localCache[$localCacheKey] ?? null;

        if ($shardConnection === null) {
            return null;
        }

        unset($this->localCache[$localCacheKey]);
        $this->localCache[$localCacheKey] = $shardConnection;

        return $shardConnection;
    }

    protected function cachedKeysForShard(string $table, string $shardConnection): array
    {
        return array_keys($this->localShardKeys[$this->generateShardPattern($table, $shardConnection)] ?? []);
    }

    protected function rememberFallbackMapping(string $table, mixed $key, string $shardConnection): void
    {
        $store = $this->getFallbackStore();

        if ($store === null) {
            return;
        }

        $mappingKey = $this->generateFallbackMappingKey($table, $key);
        $shardSetKey = $this->generateFallbackShardSetKey($table, $shardConnection);
        $existingShard = $store->get($mappingKey);

        if (is_string($existingShard) && $existingShard !== '' && $existingShard !== $shardConnection) {
            $previousSetKey = $this->generateFallbackShardSetKey($table, $existingShard);
            $previousKeys = $store->get($previousSetKey, []);

            if (is_array($previousKeys)) {
                $store->forever($previousSetKey, array_values(array_diff($previousKeys, [(string) $key])));
            }
        }

        $store->forever($mappingKey, $shardConnection);

        $members = $store->get($shardSetKey, []);
        $members = is_array($members) ? $members : [];
        $members[] = (string) $key;
        $store->forever($shardSetKey, array_values(array_unique($members)));
    }

    protected function forgetFallbackMapping(string $table, mixed $key, ?string $shardConnection = null): void
    {
        $store = $this->getFallbackStore();

        if ($store === null) {
            return;
        }

        $mappingKey = $this->generateFallbackMappingKey($table, $key);
        $resolvedShard = $shardConnection;

        if (! is_string($resolvedShard) || $resolvedShard === '') {
            $candidate = $store->get($mappingKey);
            $resolvedShard = is_string($candidate) && $candidate !== '' ? $candidate : null;
        }

        $store->forget($mappingKey);

        if ($resolvedShard === null) {
            return;
        }

        $shardSetKey = $this->generateFallbackShardSetKey($table, $resolvedShard);
        $members = $store->get($shardSetKey, []);

        if (! is_array($members)) {
            return;
        }

        $store->forever($shardSetKey, array_values(array_diff($members, [(string) $key])));
    }

    protected function locateInFallbackStore(string $table, mixed $key): ?string
    {
        $store = $this->getFallbackStore();

        if ($store === null) {
            return null;
        }

        $shardConnection = $store->get($this->generateFallbackMappingKey($table, $key));

        return is_string($shardConnection) && $shardConnection !== '' ? $shardConnection : null;
    }

    protected function fallbackKeysForShard(string $table, string $shardConnection): array
    {
        $store = $this->getFallbackStore();

        if ($store === null) {
            return [];
        }

        $members = $store->get($this->generateFallbackShardSetKey($table, $shardConnection), []);

        return is_array($members) ? $members : [];
    }

    protected function unavailable(string $context, \Throwable $previous): never
    {
        throw new ShardingException(
            "Shard locator Redis operation failed while {$context}.",
            0,
            $previous
        );
    }

    /**
     * {@inheritdoc}
     */
    public function locate(string $table, mixed $key): ?string
    {
        $cached = $this->locateInLocalCache($table, $key);
        if ($cached !== null) {
            return $cached;
        }

        if ($this->isCircuitOpen()) {
            $fallback = $this->locateInFallbackStore($table, $key);

            if ($fallback !== null) {
                $this->rememberLocalMapping($table, $key, $fallback);

                return $fallback;
            }

            throw new ShardingException(sprintf(
                'Shard locator Redis circuit is open while locating mapping for %s:%s.',
                $table,
                (string) $key
            ));
        }

        $redisKey = $this->generateRedisKey($table, $key);

        try {
            $shardConnection = $this->getConnection()->command('get', [$redisKey]);
        } catch (\Throwable $e) {
            $this->markRedisFailure();
            $fallback = $this->locateInFallbackStore($table, $key);

            if ($fallback !== null) {
                $this->rememberLocalMapping($table, $key, $fallback);

                return $fallback;
            }

            $this->unavailable(sprintf('locating mapping for %s:%s', $table, (string) $key), $e);
        }

        $this->resetCircuit();

        if (is_string($shardConnection) && $shardConnection !== '') {
            $this->rememberLocalMapping($table, $key, $shardConnection);
            $this->rememberFallbackMapping($table, $key, $shardConnection);

            return $shardConnection;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $table, mixed $key, string $shardConnection): bool
    {
        $redisKey = $this->generateRedisKey($table, $key);
        $shardMapKey = $this->generateShardPattern($table, $shardConnection);
        $existingShard = $this->locateInLocalCache($table, $key);

        if ($existingShard === null && ! $this->isCircuitOpen()) {
            try {
                $existingShard = $this->locate($table, $key);
            } catch (ShardingException) {
                $existingShard = null;
            }
        }

        if ($this->isCircuitOpen()) {
            throw new ShardingException(sprintf(
                'Shard locator Redis circuit is open while registering mapping for %s:%s.',
                $table,
                (string) $key
            ));
        }

        try {
            $connection = $this->getConnection();

            if ($existingShard !== null && $existingShard !== $shardConnection) {
                $connection->command('srem', [$this->generateShardPattern($table, $existingShard), (string) $key]);
            }

            $connection->command('set', [$redisKey, $shardConnection]);
            $connection->command('persist', [$redisKey]);
            $connection->command('sadd', [$shardMapKey, (string) $key]);
        } catch (\Throwable $e) {
            $this->markRedisFailure();
            $this->unavailable(sprintf('registering mapping for %s:%s', $table, (string) $key), $e);
        }

        $this->resetCircuit();
        $this->rememberLocalMapping($table, $key, $shardConnection);
        $this->rememberFallbackMapping($table, $key, $shardConnection);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $table, mixed $key): bool
    {
        $redisKey = $this->generateRedisKey($table, $key);
        $shardConnection = $this->locateInLocalCache($table, $key);

        if ($shardConnection === null) {
            $shardConnection = $this->locate($table, $key);
        }

        if ($shardConnection === null) {
            return false;
        }

        $shardMapKey = $this->generateShardPattern($table, $shardConnection);

        if ($this->isCircuitOpen()) {
            throw new ShardingException(sprintf(
                'Shard locator Redis circuit is open while forgetting mapping for %s:%s.',
                $table,
                (string) $key
            ));
        }

        try {
            $connection = $this->getConnection();
            $connection->command('del', [$redisKey]);
            $connection->command('srem', [$shardMapKey, (string) $key]);
        } catch (\Throwable $e) {
            $this->markRedisFailure();
            $this->unavailable(sprintf('forgetting mapping for %s:%s', $table, (string) $key), $e);
        }

        $this->resetCircuit();
        $this->forgetLocalMapping($table, $key);
        $this->forgetFallbackMapping($table, $key, $shardConnection);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getKeysForShard(string $table, string $shardConnection): array
    {
        $shardMapKey = $this->generateShardPattern($table, $shardConnection);

        if ($this->isCircuitOpen()) {
            $fallbackKeys = $this->fallbackKeysForShard($table, $shardConnection);

            return $fallbackKeys !== [] ? $fallbackKeys : $this->cachedKeysForShard($table, $shardConnection);
        }

        try {
            $members = $this->getConnection()->command('smembers', [$shardMapKey]);
        } catch (\Throwable $e) {
            $this->markRedisFailure();
            $fallbackKeys = $this->fallbackKeysForShard($table, $shardConnection);

            return $fallbackKeys !== [] ? $fallbackKeys : $this->cachedKeysForShard($table, $shardConnection);
        }

        $this->resetCircuit();

        if (is_array($members)) {
            foreach ($members as $member) {
                $this->rememberLocalMapping($table, (string) $member, $shardConnection);
                $this->rememberFallbackMapping($table, (string) $member, $shardConnection);
            }

            return $members;
        }

        return [];
    }
}
