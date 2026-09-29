<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Modules;

use Illuminate\Contracts\Foundation\Application;
use Laravel\RedisShard\Support\ModuleRegistry;

/**
 * Optional Redis-backed shard locator, map cache and Redis console helpers.
 *
 * @deprecated since 4.2.0. The service provider binds the Redis locator
 *             directly in registerCore(); this class is never instantiated.
 *             It will be removed in 5.0.
 */
class RedisModule
{
    public function __construct(
        protected Application $app,
        protected ModuleRegistry $modules,
    ) {
    }

    public function key(): string
    {
        return ModuleRegistry::REDIS;
    }

    public function isAvailable(): bool
    {
        return $this->modules->redis()
            && class_exists(\Illuminate\Redis\RedisManager::class);
    }

    public function register(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->app->singleton(\Laravel\RedisShard\Contracts\ShardLocatorInterface::class, function ($app) {
            return new \Laravel\RedisShard\Locators\RedisShardLocator(
                $app->make('redis'),
                $app->make('config'),
                $app->make('cache')
            );
        });

        $this->app->alias(\Laravel\RedisShard\Contracts\ShardLocatorInterface::class, 'shard.locator');
    }

    public function boot(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        // Redis-only console helpers stay available when the module is on.
    }
}
