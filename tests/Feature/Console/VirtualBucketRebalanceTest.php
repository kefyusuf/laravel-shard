<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Strategies\VirtualBucketStrategy;
use Laravel\RedisShard\Support\ShardRegistry;
use Laravel\RedisShard\Tests\TestCase;

class VirtualBucketRebalanceTest extends TestCase
{
    protected string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMockingConsoleOutput();

        $this->registryPath = __DIR__ . '/../../tmp/vbucket-rebalance-registry.json';
        @unlink($this->registryPath);
        @unlink($this->registryPath . '.lock');
        config()->set('redis_sharding.registry_path', $this->registryPath);
        config()->set('redis_sharding.virtual_buckets.count', 64);
        config()->set('redis_sharding.default_strategy', 'virtual_bucket');
        config()->set('redis_sharding.strategies', [
            'virtual_bucket' => \Laravel\RedisShard\Strategies\VirtualBucketStrategy::class,
        ]);
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
        VirtualBucketStrategy::flushBucketMapCache();

        parent::tearDown();
    }

    public function test_rebalance_moves_only_the_keys_of_moved_buckets(): void
    {
        $strategy = new VirtualBucketStrategy(64);
        $bucketK1 = $strategy->bucketFor('users', 'k1');
        $bucketK2 = $strategy->bucketFor('users', 'k2');
        $bucketK3 = $strategy->bucketFor('users', 'k3');

        // k1's bucket is explicitly moved to shard2; the others stay on shard1.
        ShardRegistry::writeAll(['shard1' => ['driver' => 'sqlite'], 'shard2' => ['driver' => 'sqlite']]);
        ShardRegistry::writeBucketMap([
            $bucketK1 => 'shard2',
            $bucketK2 => 'shard1',
            $bucketK3 => 'shard1',
        ]);
        VirtualBucketStrategy::flushBucketMapCache();

        $locator = new class () implements ShardLocatorInterface {
            /** @var array<string, string> */
            public array $keys = [
                'k1' => 'shard1',
                'k2' => 'shard1',
                'k3' => 'shard1',
            ];

            public function locate(string $table, mixed $key): ?string
            {
                return $this->keys[(string) $key] ?? null;
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                $this->keys[(string) $key] = $shardConnection;

                return true;
            }

            public function forget(string $table, mixed $key): bool
            {
                unset($this->keys[(string) $key]);

                return true;
            }

            public function getKeysForShard(string $table, string $shardConnection): array
            {
                $keys = [];
                foreach ($this->keys as $key => $shard) {
                    if ($shard === $shardConnection) {
                        $keys[] = $key;
                    }
                }

                return $keys;
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);
        $this->app->forgetInstance('shard.manager');

        $exitCode = Artisan::call('shard:rebalance', [
            'table' => 'users',
            '--metadata-only' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('shard2', $locator->locate('users', 'k1'));
        $this->assertSame('shard1', $locator->locate('users', 'k2'));
        $this->assertSame('shard1', $locator->locate('users', 'k3'));
    }
}
