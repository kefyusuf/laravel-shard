<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Support;

/**
 * Owns the request-scoped "which shard am I pinned to" channel.
 *
 * Writers (the shard middleware, the tenancy bridge, queue context
 * restoration) publish a shard connection here; readers (the Shardable
 * trait, the queue dispatcher) consume it. The value lives on the current
 * request's attributes, so it is per-request by construction and safe under
 * Octane. Nothing else should touch the attribute directly.
 */
class RequestShardContext
{
    public const ATTRIBUTE = 'shard_connection';

    /**
     * Pin the current request to a shard connection (null unpins).
     */
    public function set(?string $connection): void
    {
        if (! app()->bound('request')) {
            return;
        }

        app('request')->attributes->set(self::ATTRIBUTE, $connection);
    }

    /**
     * The pinned shard connection for the current request, or null.
     */
    public function get(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $connection = app('request')->attributes->get(self::ATTRIBUTE);

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
