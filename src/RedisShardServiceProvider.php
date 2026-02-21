<?php

declare(strict_types=1);

namespace Laravel\RedisShard;


use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\ServiceProvider;
use Laravel\RedisShard\Console\Commands\CreateShardCommand;
use Laravel\RedisShard\Console\Commands\InstallCommand;
use Laravel\RedisShard\Console\Commands\RebalanceShardCommand;
use Laravel\RedisShard\Console\Commands\ShardAnalyzeCommand;
use Laravel\RedisShard\Console\Commands\ShardCleanupCommand;
use Laravel\RedisShard\Console\Commands\ShardHealthCommand;
use Laravel\RedisShard\Console\Commands\ShardStatusCommand;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Database\ShardConnection;
use Laravel\RedisShard\Middleware\ShardRouteMiddleware;
use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Strategies\ConsistentHashingStrategy;
use Laravel\RedisShard\Strategies\ModuloStrategy;
use Laravel\RedisShard\Strategies\RangeBasedStrategy;
use Laravel\RedisShard\Validation\ConfigValidator;

class RedisShardServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/redis_sharding.php',
            'redis_sharding'
        );

        // Register the ShardLocator
        $this->app->singleton(ShardLocatorInterface::class, function ($app) {
            return new ShardLocator(
                $app->make('redis'),
                $app->make('config')
            );
        });

        $this->app->alias(ShardLocatorInterface::class, 'shard.locator');

        // Register the ShardManager
        $this->app->singleton('shard.manager', function ($app) {
            return new ShardManager(
                $app->make('config'),
                $app->make('db'),
                $app->make(ShardLocatorInterface::class)
            );
        });

        // Register the ShardConnection
        $this->app->singleton('shard.connection', function ($app) {
            return new ShardConnection(
                $app->make(ConnectionFactory::class)
            );
        });

        // Register the ShardRouteMiddleware
        $this->app->singleton(ShardRouteMiddleware::class, function ($app) {
            return new ShardRouteMiddleware(
                $app->make(ShardLocatorInterface::class)
            );
        });

        // Register the strategies
        $this->app->bind(ModuloStrategy::class, function () {
            return new ModuloStrategy();
        });

        $this->app->bind(ConsistentHashingStrategy::class, function () {
            return new ConsistentHashingStrategy();
        });

        $this->app->bind(RangeBasedStrategy::class, function () {
            return new RangeBasedStrategy();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Validate configuration (skip in testing)
        if (!$this->app->environment('testing')) {
            try {
                $config = config('redis_sharding', []);
                ConfigValidator::validate($config);
            } catch (\Laravel\RedisShard\Exceptions\ConfigurationException $e) {
                $strictValidation = (bool) config('redis_sharding.strict_validation', true);

                if ($this->app->environment('production') && !$strictValidation) {
                    // Log the error in production but don't crash the app
                    logger()->error('Redis Sharding Configuration Error: ' . $e->getMessage());
                } else {
                    // Throw the exception outside production or when strict validation is enabled
                    throw $e;
                }
            }
        }

        // Publish the config file
        $this->publishes([
            __DIR__ . '/../config/redis_sharding.php' => config_path('redis_sharding.php'),
        ], 'config');

        // Register the commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateShardCommand::class,
                RebalanceShardCommand::class,
                InstallCommand::class,
                ShardStatusCommand::class,
                ShardHealthCommand::class,
                ShardAnalyzeCommand::class,
                ShardCleanupCommand::class,
            ]);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register middleware
        $this->app['router']->aliasMiddleware('shard', ShardRouteMiddleware::class);
    }
}
