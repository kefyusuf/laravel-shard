<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Contracts;

/**
 * Sharding module marker. Modules are registered only when enabled in config.
 */
interface ShardModuleInterface
{
    /**
     * Stable module key (core, redis, queue, ...).
     */
    public function key(): string;

    /**
     * Whether this module can boot in the current environment.
     */
    public function isAvailable(): bool;
}
