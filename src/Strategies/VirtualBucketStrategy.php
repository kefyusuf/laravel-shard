<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Strategies;

use Laravel\RedisShard\Contracts\ShardStrategyInterface;
use Laravel\RedisShard\Support\ShardRegistry;

/**
 * Fixed virtual-bucket sharding: a key always maps to the same bucket number
 * (independent of the shard set), and a persistent bucket-to-shard map decides
 * where each bucket lives. Adding or removing shards therefore never moves a
 * bucket implicitly — operators move buckets explicitly and rebalance only
 * carries the keys of the moved buckets.
 *
 * Buckets are assigned on first touch (deterministic modulo placement) and
 * persisted into the shard registry; the assignment never changes silently.
 */
class VirtualBucketStrategy implements ShardStrategyInterface
{
    /**
     * In-process cache of the persisted bucket map, invalidated on registry
     * file mtime so concurrent command runs pick up moves.
     *
     * @var array<int, string>|null
     */
    protected static ?array $bucketMapCache = null;

    protected static ?int $bucketMapCacheMtime = null;

    protected int $bucketCount;

    public function __construct(?int $bucketCount = null)
    {
        $this->bucketCount = max(1, $bucketCount ?? (int) config('redis_sharding.virtual_buckets.count', 1024));
    }

    public function determine(string $table, mixed $key, array $availableShards): string
    {
        if (empty($availableShards)) {
            throw new \InvalidArgumentException('No available shards');
        }

        $bucket = $this->bucketFor($table, $key);
        $map = $this->bucketMap();

        $assigned = $map[$bucket] ?? null;

        if (is_string($assigned) && in_array($assigned, $availableShards, true)) {
            return $assigned;
        }

        // First touch (or stale assignment after a shard was decommissioned):
        // place deterministically and persist so shard-set changes never move
        // the bucket implicitly.
        $shard = $availableShards[$bucket % count($availableShards)];
        $map[$bucket] = $shard;

        ShardRegistry::writeBucketMap($map);
        self::flushBucketMapCache();

        return $shard;
    }

    public function bucketFor(string $table, mixed $key): int
    {
        return (int) (crc32($table . ':' . (string) $key) % $this->bucketCount);
    }

    public function getName(): string
    {
        return 'virtual_bucket';
    }

    /**
     * Drop the in-process bucket map cache (mainly for tests).
     */
    public static function flushBucketMapCache(): void
    {
        self::$bucketMapCache = null;
        self::$bucketMapCacheMtime = null;
    }

    /**
     * @return array<int, string>
     */
    protected function bucketMap(): array
    {
        $path = config('redis_sharding.registry_path');
        $mtime = is_string($path) && is_file($path) ? (int) filemtime($path) : 0;

        if (self::$bucketMapCache === null || self::$bucketMapCacheMtime !== $mtime) {
            self::$bucketMapCache = ShardRegistry::readBucketMap();
            self::$bucketMapCacheMtime = $mtime;
        }

        return self::$bucketMapCache;
    }
}
