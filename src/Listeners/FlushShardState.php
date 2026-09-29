<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Listeners;

use Illuminate\Contracts\Container\Container;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Contracts\ShardStateResettable;

/**
 * Flushes process-local locator state between long-running worker requests.
 *
 * Registered only when Laravel Octane is installed, so plain FPM/CLI apps
 * never pay for it. Locators that are not state-resettable are ignored.
 */
class FlushShardState
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * @param object $event Octane RequestTerminated (or any request-lifecycle event)
     */
    public function handle(object $event): void
    {
        $locator = $this->app->make(ShardLocatorInterface::class);

        if ($locator instanceof ShardStateResettable) {
            $locator->resetState();
        }
    }
}
