<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Tests\TestCase;

class ShardCleanupCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfRedisNotAvailable();
        $this->app->make('redis')->flushall();
        $this->prepareDeterministicShards();
    }

    public function test_dry_run_does_not_delete_orphaned_keys(): void
    {
        $redis = $this->app->make('redis')->connection(config('redis_sharding.redis_connection', 'default'));
        $invalidShard = 'ghost_shard_' . bin2hex(random_bytes(4));
        $redis->set('shard:users:1001', $invalidShard);

        $this->artisan('shard:cleanup', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull($redis->get('shard:users:1001'));
    }

    public function test_it_rejects_unsupported_output_format(): void
    {
        $this->artisan('shard:cleanup', [
            '--format' => 'xml',
        ])
            ->expectsOutput('Unsupported format "xml". Allowed: table, json.')
            ->assertExitCode(1);
    }

    public function test_it_cleans_orphaned_keys_and_stale_shard_maps(): void
    {
        $redis = $this->app->make('redis')->connection(config('redis_sharding.redis_connection', 'default'));
        $invalidShard = 'ghost_shard_' . bin2hex(random_bytes(4));

        // Orphaned because shard does not exist in configured shards.
        $redis->set('shard:users:2001', $invalidShard);

        // Stale set entry because actual locator key points to a different shard.
        $redis->set('shard:users:3001', 'shard2');
        $redis->sadd('shard_map:users:shard1', '3001');

        $this->artisan('shard:cleanup')->assertExitCode(0);

        $this->assertNull($redis->get('shard:users:2001'));
        $this->assertFalse((bool) $redis->sismember('shard_map:users:shard1', '3001'));
    }

    public function test_json_format_outputs_structured_cleanup_summary(): void
    {
        $redis = $this->app->make('redis')->connection(config('redis_sharding.redis_connection', 'default'));
        $invalidShard = 'ghost_shard_' . bin2hex(random_bytes(4));
        $redis->set('shard:users:5001', $invalidShard);

        $this->artisan('shard:cleanup', [
            '--dry-run' => true,
            '--format' => 'json',
        ])
            ->assertExitCode(0);
    }

    protected function prepareDeterministicShards(): void
    {
        $registryPath = __DIR__ . '/../../tmp/cleanup-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');
    }
}
