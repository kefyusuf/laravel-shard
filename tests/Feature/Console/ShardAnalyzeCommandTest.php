<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Tests\TestCase;

class ShardAnalyzeCommandTest extends TestCase
{
    public function test_it_rejects_invalid_sample_size(): void
    {
        $this->artisan('shard:analyze', [
            '--sample-size' => 0,
        ])
            ->expectsOutput('Sample size must be at least 2.')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_no_shards_are_configured(): void
    {
        $registryPath = __DIR__ . '/../../tmp/analyze-no-shards-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        config()->set('redis_sharding.connections', []);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');

        $this->artisan('shard:analyze', [
            '--sample-size' => 10,
        ])
            ->expectsOutput('No available shards found.')
            ->assertExitCode(1);
    }

    public function test_table_mode_does_not_require_sample_size(): void
    {
        $locator = new class implements ShardLocatorInterface {
            public function locate(string $table, mixed $key): ?string
            {
                return null;
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                return true;
            }

            public function forget(string $table, mixed $key): bool
            {
                return true;
            }

            public function getKeysForShard(string $table, string $shardConnection): array
            {
                return [];
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);

        $this->artisan('shard:analyze', [
            'table' => 'users',
            '--sample-size' => 0,
        ])
            ->expectsOutput('Analyzing table: users')
            ->expectsOutput("No keys found for table 'users'")
            ->assertExitCode(0);
    }
}
