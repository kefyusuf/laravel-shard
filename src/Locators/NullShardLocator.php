<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Locators;

use Laravel\RedisShard\Contracts\ShardLocatorInterface;

/**
 * Locator that never stores mappings. Routing falls through to strategies only.
 */
class NullShardLocator implements ShardLocatorInterface
{
    public function locate(string $table, mixed $key): ?string
    {
        return null;
    }

    public function register(string $table, mixed $key, string $shardConnection): bool
    {
        return true;
    }

    public function forget(string $table, mixed $key): bool
    {
        return true;
    }

    public function getKeysForShard(string $table, string $shardConnection): array
    {
        return [];
    }

    public function resetState(): void
    {
        // Stateless by design; nothing to flush.
    }
}
