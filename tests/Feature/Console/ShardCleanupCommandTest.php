<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Tests\TestCase;

class ShardCleanupCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfRedisNotAvailable();
        $this->app->make('redis')->flushall();
    }

    public function test_dry_run_does_not_delete_orphaned_keys(): void
    {
        $redis = $this->app->make('redis')->connection(config('redis_sharding.redis_connection', 'default'));
        $redis->set('shard:users:1001', 'ghost_shard');

        $this->artisan('shard:cleanup', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull($redis->get('shard:users:1001'));
    }

    public function test_it_cleans_orphaned_keys_and_stale_shard_maps(): void
    {
        $redis = $this->app->make('redis')->connection(config('redis_sharding.redis_connection', 'default'));

        // Orphaned because shard does not exist in configured shards.
        $redis->set('shard:users:2001', 'ghost_shard');

        // Stale set entry because actual locator key points to a different shard.
        $redis->set('shard:users:3001', 'shard2');
        $redis->sadd('shard_map:users:shard1', '3001');

        $this->artisan('shard:cleanup')->assertExitCode(0);

        $this->assertNull($redis->get('shard:users:2001'));
        $this->assertSame(0, $redis->sismember('shard_map:users:shard1', '3001'));
    }
}
