<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Laravel\RedisShard\Support\ShardRegistry;
use Laravel\RedisShard\Tests\TestCase;

class VirtualBucketCommandTest extends TestCase
{
    protected string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase's artisan() helper leaves a mocked OutputStyle bound
        // in the container on Laravel < 13, which swallows Artisan::call output.
        $this->withoutMockingConsoleOutput();

        $this->registryPath = __DIR__ . '/../../tmp/vbucket-command-registry.json';
        @unlink($this->registryPath);
        @unlink($this->registryPath . '.lock');
        config()->set('redis_sharding.registry_path', $this->registryPath);
        config()->set('redis_sharding.virtual_buckets.count', 64);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $this->app->forgetInstance('shard.manager');
    }

    protected function tearDown(): void
    {
        @unlink($this->registryPath);
        @unlink($this->registryPath . '.lock');

        parent::tearDown();
    }

    public function test_bucket_status_reports_distribution_as_json(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite'], 'shard2' => ['driver' => 'sqlite']]);
        ShardRegistry::writeBucketMap([1 => 'shard1', 2 => 'shard1', 3 => 'shard2']);

        $exitCode = Artisan::call('shard:bucket-status', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame(64, $payload['summary']['bucket_count'] ?? null);
        $this->assertSame(3, $payload['summary']['assigned'] ?? null);
        $this->assertSame(61, $payload['summary']['unassigned'] ?? null);
        $this->assertSame(2, $payload['shards']['shard1'] ?? null);
        $this->assertSame(1, $payload['shards']['shard2'] ?? null);
    }

    public function test_bucket_move_persists_the_assignment(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite'], 'shard2' => ['driver' => 'sqlite']]);

        $exitCode = Artisan::call('shard:bucket', ['bucket' => 5, 'shard' => 'shard2']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('shard2', ShardRegistry::readBucketMap()[5]);
    }

    public function test_bucket_move_rejects_an_unknown_shard(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite']]);

        $exitCode = Artisan::call('shard:bucket', ['bucket' => 5, 'shard' => 'nope']);

        $this->assertSame(1, $exitCode);
        $this->assertSame([], ShardRegistry::readBucketMap());
    }

    public function test_bucket_move_rejects_an_out_of_range_bucket(): void
    {
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite']]);
        config()->set('redis_sharding.virtual_buckets.count', 64);

        $exitCode = Artisan::call('shard:bucket', ['bucket' => 64, 'shard' => 'shard1']);

        $this->assertSame(1, $exitCode);
    }
}
