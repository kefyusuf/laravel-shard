<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Cache;

use Laravel\RedisShard\Cache\ShardCache;
use Laravel\RedisShard\Tests\TestCase;

class ShardCacheTest extends TestCase
{
    public function test_flush_only_clears_shard_cache_keys(): void
    {
        $store = $this->app->make('cache')->store('array');
        $store->put('external:key', 'keep', 3600);

        $cache = new ShardCache($store, 3600, 'shard_cache_test');
        $cache->putShardConnection('users', 1, 'shard1');
        $cache->putShardMetadata('shard1', ['status' => 'active']);
        $cache->flush();

        $this->assertSame('keep', $store->get('external:key'));
        $this->assertNull($cache->getShardConnection('users', 1));
        $this->assertNull($cache->getShardMetadata('shard1'));
    }

    public function test_invalidate_table_only_removes_target_table_keys(): void
    {
        $store = $this->app->make('cache')->store('array');
        $cache = new ShardCache($store, 3600, 'shard_cache_test');

        $cache->putShardConnection('users', 1, 'shard1');
        $cache->putShardConnection('orders', 2, 'shard2');

        $cache->invalidateTable('users');

        $this->assertNull($cache->getShardConnection('users', 1));
        $this->assertSame('shard2', $cache->getShardConnection('orders', 2));
    }
}
