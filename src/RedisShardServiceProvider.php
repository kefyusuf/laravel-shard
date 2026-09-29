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
use Laravel\RedisShard\Console\Commands\ShardReportCommand;
use Laravel\RedisShard\Console\Commands\ShardStatusCommand;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Database\ShardConnection;
use Laravel\RedisShard\Exceptions\ConfigurationException;
use Laravel\RedisShard\Http\ShardHealthController;
use Laravel\RedisShard\Listeners\FlushShardState;
use Laravel\RedisShard\Locators\ArrayShardLocator;
use Laravel\RedisShard\Metrics\Pulse\RequestShardUsage;
use Laravel\RedisShard\Middleware\ShardRouteMiddleware;
use Laravel\RedisShard\Modules\QueueModule;
use Laravel\RedisShard\Queue\RestoreShardContext;
use Laravel\RedisShard\Rebalance\DatabaseRebalanceDataMover;
use Laravel\RedisShard\Strategies\ConsistentHashingStrategy;
use Laravel\RedisShard\Strategies\ModuloStrategy;
use Laravel\RedisShard\Strategies\RangeBasedStrategy;
use Laravel\RedisShard\Support\ModuleRegistry;
use Laravel\RedisShard\Validation\ConfigValidator;

class RedisShardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/redis_sharding.php',
            'redis_sharding'
        );

        // Resolved lazily so environment config (and tests) can enable/disable modules
        // after provider registration, matching Testbench bootstrap order.
        $this->app->singleton(ModuleRegistry::class, function ($app) {
            return ModuleRegistry::fromConfig($app['config']->get('redis_sharding.modules', []));
        });

        $this->registerCore();
        $this->registerOptionalModules();
    }

    protected function registerCore(): void
    {
        $this->app->singleton(RequestShardUsage::class);
        $this->app->singleton(\Laravel\RedisShard\Database\ReadReplicaResolver::class);

        $this->app->singleton(ShardLocatorInterface::class, function ($app) {
            /** @var ModuleRegistry $modules */
            $modules = $app->make(ModuleRegistry::class);

            if ($modules->redis() && class_exists(\Illuminate\Redis\RedisManager::class)) {
                return new \Laravel\RedisShard\Locators\RedisShardLocator(
                    $app->make('redis'),
                    $app->make('config'),
                    $app->make('cache')
                );
            }

            return new ArrayShardLocator();
        });
        $this->app->alias(ShardLocatorInterface::class, 'shard.locator');

        $this->app->singleton('shard.manager', function ($app) {
            return new ShardManager(
                $app->make('config'),
                $app->make('db'),
                $app->make(ShardLocatorInterface::class),
                $app,
                $app->make(RequestShardUsage::class)
            );
        });

        $this->app->singleton('shard.connection', function ($app) {
            return new ShardConnection(
                $app->make(ConnectionFactory::class)
            );
        });

        $this->app->singleton(ShardRouteMiddleware::class, function ($app) {
            return new ShardRouteMiddleware(
                $app->make(ShardLocatorInterface::class)
            );
        });

        $this->app->bind(ModuloStrategy::class, fn () => new ModuloStrategy());
        $this->app->bind(ConsistentHashingStrategy::class, fn () => new ConsistentHashingStrategy());
        $this->app->bind(RangeBasedStrategy::class, fn () => new RangeBasedStrategy());

        if ((bool) $this->app['config']->get('redis_sharding.rebalance.enable_default_data_mover', false)) {
            $this->app->singleton(RebalanceDataMoverInterface::class, function () {
                return new DatabaseRebalanceDataMover();
            });
        }
    }

    protected function registerOptionalModules(): void
    {
        // Optional modules only add extra bindings; locator choice is lazy in core.
        $this->app->singleton(RestoreShardContext::class);
    }

    public function boot(): void
    {
        // Validation runs in every environment, including testing: config bugs
        // caught here must not silently ship to consumer CI runs. Only the
        // strict_validation flag decides whether a bad config throws or warns.
        try {
            $config = config('redis_sharding', []);
            $connections = $config['connections'] ?? [];

            if (is_array($connections) && ! empty($connections)) {
                ConfigValidator::validate($config);
            }
        } catch (ConfigurationException $e) {
            $strictValidation = (bool) config('redis_sharding.strict_validation', true);

            if ($strictValidation) {
                throw $e;
            }

            logger()->warning('Redis Sharding Configuration Error: ' . $e->getMessage());
        }

        $this->publishes([
            __DIR__ . '/../config/redis_sharding.php' => config_path('redis_sharding.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateShardCommand::class,
                RebalanceShardCommand::class,
                InstallCommand::class,
                ShardStatusCommand::class,
                ShardHealthCommand::class,
                ShardReportCommand::class,
                ShardAnalyzeCommand::class,
                ShardCleanupCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'redis-shard');

        $this->registerPulseIntegration();
        $this->registerTenancyBridge();

        $this->app->make('router')->aliasMiddleware('shard', ShardRouteMiddleware::class);

        $this->registerHealthRoute();
        \Laravel\RedisShard\Metrics\HealthRegistrar::register();

        $this->registerOctaneStateFlush();

        /** @var ModuleRegistry $modules */
        $modules = $this->app->make(ModuleRegistry::class);

        if ($modules->queue()) {
            (new QueueModule($this->app, $modules))->boot();
        }
    }

    protected function registerPulseIntegration(): void
    {
        if (! class_exists(\Laravel\Pulse\Pulse::class)
            || ! (bool) config('pulse.enabled', false)
            || ! (bool) config('redis_sharding.pulse.enabled', true)) {
            return;
        }

        // Deferred so both Pulse and Livewire are fully booted, regardless of
        // package provider boot order.
        $this->app->afterResolving('livewire', function (): void {
            \Livewire\Livewire::component('redis-shard.shard-usage', \Laravel\RedisShard\Metrics\Pulse\ShardUsageCard::class);
        });

        $this->app->afterResolving(\Laravel\Pulse\Pulse::class, function (\Laravel\Pulse\Pulse $pulse): void {
            $pulse->register([
                \Laravel\RedisShard\Metrics\Pulse\ShardUsageRecorder::class => ['enabled' => true],
            ]);
        });
    }

    protected function registerTenancyBridge(): void
    {
        $driver = (string) config('redis_sharding.tenancy.driver', '');

        if ($driver === 'stancl' && class_exists(\Stancl\Tenancy\Tenancy::class)) {
            // Event names as strings: the tenancy packages are optional deps.
            $this->app['events']->listen(
                'Stancl\Tenancy\Events\TenancyInitialized',
                \Laravel\RedisShard\Tenancy\StanclTenancyListener::class
            );
        } elseif ($driver === 'spatie' && class_exists(\Spatie\Multitenancy\Multitenancy::class)) {
            $this->app['events']->listen(
                'Spatie\Multitenancy\Events\TenantFound',
                \Laravel\RedisShard\Tenancy\SpatieTenantListener::class
            );
        }
    }

    protected function registerOctaneStateFlush(): void
    {
        // Without Octane there are no long-lived worker requests, so the
        // locator's process-local state is per-request anyway.
        if (! class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            return;
        }

        $this->app->make('events')->listen(
            \Laravel\Octane\Events\RequestTerminated::class,
            FlushShardState::class
        );
    }

    protected function registerHealthRoute(): void
    {
        if (! (bool) config('redis_sharding.metrics.enabled', false)) {
            return;
        }

        $path = (string) config('redis_sharding.metrics.path', '/shard-health');
        $middleware = (array) config('redis_sharding.metrics.middleware', ['web']);

        $this->app->make('router')
            ->middleware($middleware)
            ->get($path, ShardHealthController::class)
            ->name('shard.health');
    }

    public function modules(): ModuleRegistry
    {
        return $this->app->make(ModuleRegistry::class);
    }
}
