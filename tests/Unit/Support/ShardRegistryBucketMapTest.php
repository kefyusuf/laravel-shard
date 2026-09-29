<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Support;

use Laravel\RedisShard\Support\ShardRegistry;
use Laravel\RedisShard\Tests\TestCase;

class ShardRegistryBucketMapTest extends TestCase
{
    protected string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registryPath = __DIR__ . '/../../tmp/bucket-registry-test.json';
        @unlink($this->registryPath);
        @unlink($this->registryPath . '.lock');
        config()->set('redis_sharding.registry_path', $this->registryPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->registryPath);
        @unlink($this->registryPath . '.lock');

        parent::tearDown();
    }

    public function test_legacy_registry_file_still_reads_as_connections(): void
    {
        file_put_contents($this->registryPath, json_encode([
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));

        $connections = ShardRegistry::readAll();

        $this->assertSame(['shard1'], array_keys($connections));
        $this->assertSame([], ShardRegistry::readBucketMap());
    }

    public function test_bucket_map_round_trips_with_int_keys(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite'], 'shard2' => ['driver' => 'sqlite']]);

        ShardRegistry::writeBucketMap([7 => 'shard1', 1023 => 'shard2']);

        $this->assertSame([7 => 'shard1', 1023 => 'shard2'], ShardRegistry::readBucketMap());
        $this->assertSame(['shard1', 'shard2'], array_keys(ShardRegistry::readAll()));
    }

    public function test_write_all_preserves_the_bucket_map(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite']]);
        ShardRegistry::writeBucketMap([3 => 'shard1']);

        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite'], 'shard2' => ['driver' => 'sqlite']]);

        $this->assertSame([3 => 'shard1'], ShardRegistry::readBucketMap());
        $this->assertSame(['shard1', 'shard2'], array_keys(ShardRegistry::readAll()));
    }

    public function test_write_bucket_map_preserves_connections(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite']]);

        ShardRegistry::writeBucketMap([9 => 'shard1']);

        $connections = ShardRegistry::readAll();
        $this->assertSame(['shard1'], array_keys($connections));
        $this->assertSame('sqlite', $connections['shard1']['driver']);
    }

    public function test_upsert_still_works_on_versioned_files(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite']]);
        ShardRegistry::writeBucketMap([5 => 'shard1']);

        ShardRegistry::upsert('shard2', ['driver' => 'sqlite']);

        $this->assertSame(['shard1', 'shard2'], array_keys(ShardRegistry::readAll()));
        $this->assertSame([5 => 'shard1'], ShardRegistry::readBucketMap());
    }
}
