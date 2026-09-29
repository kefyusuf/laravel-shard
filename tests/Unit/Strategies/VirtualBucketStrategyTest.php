<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Strategies;

use Laravel\RedisShard\Strategies\VirtualBucketStrategy;
use Laravel\RedisShard\Support\ShardRegistry;
use Laravel\RedisShard\Tests\TestCase;

class VirtualBucketStrategyTest extends TestCase
{
    protected string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registryPath = __DIR__ . '/../../tmp/vbucket-registry-test.json';
        @unlink($this->registryPath);
        @unlink($this->registryPath . '.lock');
        config()->set('redis_sharding.registry_path', $this->registryPath);
        VirtualBucketStrategy::flushBucketMapCache();
    }

    protected function tearDown(): void
    {
        @unlink($this->registryPath);
        @unlink($this->registryPath . '.lock');
        VirtualBucketStrategy::flushBucketMapCache();

        parent::tearDown();
    }

    public function test_bucket_assignment_is_deterministic_and_independent_of_shard_set(): void
    {
        $strategy = new VirtualBucketStrategy(64);

        $first = $strategy->bucketFor('users', 'a@example.com');

        $this->assertSame($first, $strategy->bucketFor('users', 'a@example.com'));
        $this->assertNotSame($strategy->bucketFor('users', 'b@example.com'), $first);
        $this->assertLessThan(64, $first);
    }

    public function test_first_touch_assigns_and_persists_the_bucket(): void
    {
        $strategy = new VirtualBucketStrategy(64);

        $shard = $strategy->determine('users', 'k1', ['shard1', 'shard2']);

        $this->assertContains($shard, ['shard1', 'shard2']);

        $map = ShardRegistry::readBucketMap();
        $bucket = $strategy->bucketFor('users', 'k1');
        $this->assertSame($shard, $map[$bucket]);

        // Second determination reads the persisted assignment.
        $this->assertSame($shard, $strategy->determine('users', 'k1', ['shard1', 'shard2']));
    }

    public function test_honors_an_existing_bucket_assignment(): void
    {
        $strategy = new VirtualBucketStrategy(64);
        $bucket = $strategy->bucketFor('users', 'k1');

        ShardRegistry::writeBucketMap([$bucket => 'shard2']);
        VirtualBucketStrategy::flushBucketMapCache();

        $this->assertSame('shard2', $strategy->determine('users', 'k1', ['shard1', 'shard2']));
    }

    public function test_stale_bucket_assignment_falls_back_and_is_repaired(): void
    {
        $strategy = new VirtualBucketStrategy(64);
        $bucket = $strategy->bucketFor('users', 'k1');

        ShardRegistry::writeBucketMap([$bucket => 'retired_shard']);
        VirtualBucketStrategy::flushBucketMapCache();

        $shard = $strategy->determine('users', 'k1', ['shard1', 'shard2']);

        $this->assertContains($shard, ['shard1', 'shard2']);
        $this->assertSame($shard, ShardRegistry::readBucketMap()[$bucket]);
    }

    public function test_buckets_never_move_when_shards_are_added(): void
    {
        $strategy = new VirtualBucketStrategy(64);

        $initial = $strategy->determine('users', 'k1', ['shard1', 'shard2']);
        $strategy->determine('users', 'k2', ['shard1', 'shard2']);

        // A third shard joins: existing assignments must not change.
        $this->assertSame($initial, $strategy->determine('users', 'k1', ['shard1', 'shard2', 'shard3']));
    }

    public function test_rejects_empty_shard_list(): void
    {
        $strategy = new VirtualBucketStrategy(64);

        $this->expectException(\InvalidArgumentException::class);

        $strategy->determine('users', 'k1', []);
    }

    public function test_name_is_virtual_bucket(): void
    {
        $this->assertSame('virtual_bucket', (new VirtualBucketStrategy(64))->getName());
    }
}
