<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Modules;

use Illuminate\Contracts\Foundation\Application;
use Laravel\RedisShard\Queue\RestoreShardContext;
use Laravel\RedisShard\Queue\ShardContextDispatcher;
use Laravel\RedisShard\Support\ModuleRegistry;

/**
 * Optional shard-aware queue integration.
 */
class QueueModule
{
    protected ?ShardContextDispatcher $dispatcher = null;

    public function __construct(
        protected Application $app,
        protected ModuleRegistry $modules,
    ) {
    }

    public function key(): string
    {
        return ModuleRegistry::QUEUE;
    }

    public function isAvailable(): bool
    {
        return $this->modules->queue();
    }

    public function register(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->app->singleton(RestoreShardContext::class);
    }

    public function boot(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->dispatcher = new ShardContextDispatcher();
        $this->dispatcher->boot();
    }
}
