<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\RedisShardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $registryPath = __DIR__ . '/tmp/default-test-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');
    }

    protected function getPackageProviders($app): array
    {
        return [
            RedisShardServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Redis configuration for testing
        config()->set('database.redis.client', 'predis');
        config()->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0,
        ]);

        // Redis sharding configuration
        config()->set('redis_sharding', [
            'redis_connection' => 'default',
            'default_strategy' => 'consistent_hashing',
            'strategies' => [
                'modulo' => \Laravel\RedisShard\Strategies\ModuloStrategy::class,
                'consistent_hashing' => \Laravel\RedisShard\Strategies\ConsistentHashingStrategy::class,
                'range_based' => \Laravel\RedisShard\Strategies\RangeBasedStrategy::class,
            ],
            'connections' => [
                'shard1' => [
                    'driver' => 'sqlite',
                    'host' => 'localhost',
                    'database' => ':memory:',
                    'username' => 'root',
                    'password' => '',
                    'prefix' => 'shard1_',
                ],
                'shard2' => [
                    'driver' => 'sqlite',
                    'host' => 'localhost',
                    'database' => ':memory:',
                    'username' => 'root',
                    'password' => '',
                    'prefix' => 'shard2_',
                ],
                'shard3' => [
                    'driver' => 'sqlite',
                    'host' => 'localhost',
                    'database' => ':memory:',
                    'prefix' => 'shard3_',
                ],
            ],
            'auto_provisioning' => [
                'enabled' => false,
                'max_shards' => 10,
                'threshold' => 1000000,
            ],
            'metadata_table' => 'shard_metadata',
            'cache_ttl' => 3600,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Check if Redis is available for testing.
     *
     * @return bool
     */
    protected function isRedisAvailable(): bool
    {
        try {
            $this->app->make('redis')->connection()->command('ping');

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Skip the test if Redis is not available.
     *
     * @return void
     */
    protected function skipIfRedisNotAvailable(): void
    {
        if (! $this->isRedisAvailable()) {
            $this->markTestSkipped('Redis is not available for this test.');
        }
    }
}
