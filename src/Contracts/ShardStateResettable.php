<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Contracts;

/**
 * Locators that hold process-local state (caches, circuit breakers) implement
 * this contract so long-running workers (Octane, queue workers) can flush the
 * state between requests without being recreated.
 */
interface ShardStateResettable
{
    /**
     * Drop all process-local state: cached mappings and circuit-breaker status.
     *
     * @return void
     */
    public function resetState(): void;
}
