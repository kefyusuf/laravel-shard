<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Modules;

use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Locators\ArrayShardLocator;
use Laravel\RedisShard\RedisShardServiceProvider;
use Laravel\RedisShard\Support\ModuleRegistry;
use Orchestra\Testbench\TestCase as Orchestra;

class ModuleWiringTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RedisShardServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('redis_sharding.registry_path', __DIR__ . '/../../tmp/module-wiring-registry.json');
        $app['config']->set('redis_sharding.connections', []);
        $app['config']->set('redis_sharding.modules', [
            'core' => true,
            'redis' => false,
            'queue' => false,
        ]);
    }

    protected function resolveLocator(): ShardLocatorInterface
    {
        $this->app->forgetInstance(ShardLocatorInterface::class);
        $this->app->forgetInstance('shard.locator');
        $this->app->forgetInstance(ModuleRegistry::class);
        $this->app->forgetInstance('shard.manager');

        return $this->app->make(ShardLocatorInterface::class);
    }

    public function test_core_module_registers_array_locator_when_redis_disabled(): void
    {
        $this->assertInstanceOf(ArrayShardLocator::class, $this->resolveLocator());
    }

    public function test_module_registry_reflects_config(): void
    {
        $this->app->forgetInstance(ModuleRegistry::class);
        $registry = $this->app->make(ModuleRegistry::class);

        $this->assertFalse($registry->redis());
        $this->assertFalse($registry->queue());
        $this->assertTrue($registry->enabled(ModuleRegistry::CORE));
    }

    public function test_manager_works_without_redis(): void
    {
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'shard2' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        $this->app->forgetInstance('shard.manager');
        $manager = $this->app->make('shard.manager');
        $connection = $manager->getShardConnection('users', 'user-1');

        $this->assertContains($connection, ['shard1', 'shard2']);
    }

    public function test_queue_module_can_be_enabled(): void
    {
        config()->set('redis_sharding.modules.queue', true);
        $this->app->forgetInstance(ModuleRegistry::class);

        $registry = $this->app->make(ModuleRegistry::class);

        $this->assertTrue($registry->queue());
    }

    public function test_redis_module_uses_redis_locator_when_enabled(): void
    {
        if (!class_exists(\Illuminate\Redis\RedisManager::class)) {
            $this->markTestSkipped('illuminate/redis is not installed.');
        }

        config()->set('redis_sharding.modules.redis', true);
        config()->set('database.redis.client', 'predis');
        config()->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0,
        ]);

        $locator = $this->resolveLocator();

        $this->assertInstanceOf(\Laravel\RedisShard\Locators\RedisShardLocator::class, $locator);
    }
}
