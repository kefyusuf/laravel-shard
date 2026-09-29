<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics;

use Illuminate\Support\Facades\Health;

/**
 * Registers the shard health check with Laravel's health component when
 * that component is installed. No-op otherwise.
 */
class HealthRegistrar
{
    public static function register(): void
    {
        if (! class_exists(Health::class)) {
            return;
        }

        if (! class_exists(\Illuminate\Health\Checks\Check::class)) {
            return;
        }

        Health::checks([
            LaravelShardHealthCheck::class,
        ]);
    }
}
