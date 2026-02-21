<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class ShardCache
{
    /**
     * @var CacheRepository
     */
    protected CacheRepository $cache;

    /**
     * @var int
     */
    protected int $defaultTtl;

    /**
     * @var string
     */
    protected string $prefix;

    /**
     * @var string
     */
    protected string $indexKey;

    /**
     * Create a new shard cache instance.
     *
     * @param CacheRepository|null $cache
     * @param int $defaultTtl
     * @param string $prefix
     */
    public function __construct(?CacheRepository $cache = null, int $defaultTtl = 3600, string $prefix = 'shard_cache')
    {
        $this->cache = $cache ?: Cache::store();
        $this->defaultTtl = $defaultTtl;
        $this->prefix = $prefix;
        $this->indexKey = $this->prefix . ':__index';
    }

    /**
     * Get shard connection from cache.
     *
     * @param string $table
     * @param mixed $key
     * @return string|null
     */
    public function getShardConnection(string $table, mixed $key): ?string
    {
        $cacheKey = $this->generateCacheKey('connection', $table, $key);
        return $this->cache->get($cacheKey);
    }

    /**
     * Cache shard connection.
     *
     * @param string $table
     * @param mixed $key
     * @param string $shardConnection
     * @param int|null $ttl
     * @return void
     */
    public function putShardConnection(string $table, mixed $key, string $shardConnection, ?int $ttl = null): void
    {
        $cacheKey = $this->generateCacheKey('connection', $table, $key);
        $this->cache->put($cacheKey, $shardConnection, $ttl ?: $this->defaultTtl);
        $this->rememberTrackedKey($cacheKey);
    }

    /**
     * Get shard metadata from cache.
     *
     * @param string $shardName
     * @return array|null
     */
    public function getShardMetadata(string $shardName): ?array
    {
        $cacheKey = $this->generateCacheKey('metadata', $shardName);
        return $this->cache->get($cacheKey);
    }

    /**
     * Cache shard metadata.
     *
     * @param string $shardName
     * @param array $metadata
     * @param int|null $ttl
     * @return void
     */
    public function putShardMetadata(string $shardName, array $metadata, ?int $ttl = null): void
    {
        $cacheKey = $this->generateCacheKey('metadata', $shardName);
        $this->cache->put($cacheKey, $metadata, $ttl ?: $this->defaultTtl);
        $this->rememberTrackedKey($cacheKey);
    }

    /**
     * Get available shards from cache.
     *
     * @return array|null
     */
    public function getAvailableShards(): ?array
    {
        $cacheKey = $this->generateCacheKey('available_shards');
        return $this->cache->get($cacheKey);
    }

    /**
     * Cache available shards.
     *
     * @param array $shards
     * @param int|null $ttl
     * @return void
     */
    public function putAvailableShards(array $shards, ?int $ttl = null): void
    {
        $cacheKey = $this->generateCacheKey('available_shards');
        $this->cache->put($cacheKey, $shards, $ttl ?: $this->defaultTtl);
        $this->rememberTrackedKey($cacheKey);
    }

    /**
     * Get strategy instance from cache.
     *
     * @param string $strategyName
     * @return mixed
     */
    public function getStrategy(string $strategyName): mixed
    {
        $cacheKey = $this->generateCacheKey('strategy', $strategyName);
        return $this->cache->get($cacheKey);
    }

    /**
     * Cache strategy instance.
     *
     * @param string $strategyName
     * @param mixed $strategy
     * @param int|null $ttl
     * @return void
     */
    public function putStrategy(string $strategyName, mixed $strategy, ?int $ttl = null): void
    {
        $cacheKey = $this->generateCacheKey('strategy', $strategyName);
        $this->cache->put($cacheKey, $strategy, $ttl ?: ($this->defaultTtl * 24)); // Cache strategies longer
        $this->rememberTrackedKey($cacheKey);
    }

    /**
     * Invalidate cache for a specific table and key.
     *
     * @param string $table
     * @param mixed $key
     * @return void
     */
    public function invalidateShardConnection(string $table, mixed $key): void
    {
        $cacheKey = $this->generateCacheKey('connection', $table, $key);
        $this->cache->forget($cacheKey);
        $this->forgetTrackedKey($cacheKey);
    }

    /**
     * Invalidate all cache for a table.
     *
     * @param string $table
     * @return void
     */
    public function invalidateTable(string $table): void
    {
        $prefix = $this->generateCacheKey('connection', $table);

        foreach ($this->getTrackedKeys() as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix . ':')) {
                $this->cache->forget($cacheKey);
                $this->forgetTrackedKey($cacheKey);
            }
        }
    }

    /**
     * Invalidate shard metadata cache.
     *
     * @param string $shardName
     * @return void
     */
    public function invalidateShardMetadata(string $shardName): void
    {
        $cacheKey = $this->generateCacheKey('metadata', $shardName);
        $this->cache->forget($cacheKey);
        $this->forgetTrackedKey($cacheKey);
    }

    /**
     * Invalidate available shards cache.
     *
     * @return void
     */
    public function invalidateAvailableShards(): void
    {
        $cacheKey = $this->generateCacheKey('available_shards');
        $this->cache->forget($cacheKey);
        $this->forgetTrackedKey($cacheKey);
    }

    /**
     * Clear all shard-related cache.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach ($this->getTrackedKeys() as $cacheKey) {
            $this->cache->forget($cacheKey);
        }

        $this->cache->forget($this->indexKey);
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public function getStats(): array
    {
        // This would depend on your cache driver's capabilities
        return [
            'driver' => get_class($this->cache),
            'prefix' => $this->prefix,
            'default_ttl' => $this->defaultTtl,
        ];
    }

    /**
     * Warm up cache with frequently accessed data.
     *
     * @param array $tables
     * @param array $keys
     * @return void
     */
    public function warmUp(array $tables, array $keys): void
    {
        // Pre-populate cache with frequently accessed shard connections
        foreach ($tables as $table) {
            foreach ($keys as $key) {
                // This would typically be called after determining the shard connection
                // to pre-populate the cache
            }
        }
    }

    /**
     * Generate cache key.
     *
     * @param string $type
     * @param mixed ...$parts
     * @return string
     */
    protected function generateCacheKey(string $type, ...$parts): string
    {
        $keyParts = [$this->prefix, $type];
        
        foreach ($parts as $part) {
            if (is_array($part) || is_object($part)) {
                $keyParts[] = md5(serialize($part));
            } else {
                $keyParts[] = (string) $part;
            }
        }
        
        return implode(':', $keyParts);
    }

    /**
     * @return array<int, string>
     */
    protected function getTrackedKeys(): array
    {
        $keys = $this->cache->get($this->indexKey, []);

        return is_array($keys) ? array_values(array_unique(array_map('strval', $keys))) : [];
    }

    protected function rememberTrackedKey(string $cacheKey): void
    {
        $keys = $this->getTrackedKeys();
        if (!in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            $this->cache->put($this->indexKey, $keys, $this->defaultTtl * 24);
        }
    }

    protected function forgetTrackedKey(string $cacheKey): void
    {
        $keys = array_values(array_filter(
            $this->getTrackedKeys(),
            fn ($key) => $key !== $cacheKey
        ));

        if (empty($keys)) {
            $this->cache->forget($this->indexKey);
            return;
        }

        $this->cache->put($this->indexKey, $keys, $this->defaultTtl * 24);
    }

    /**
     * Get cache key for debugging.
     *
     * @param string $type
     * @param mixed ...$parts
     * @return string
     */
    public function getCacheKey(string $type, ...$parts): string
    {
        return $this->generateCacheKey($type, ...$parts);
    }
}
