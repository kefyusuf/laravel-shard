<?php

declare(strict_types=1);

namespace Laravel\RedisShard;

use Laravel\RedisShard\Locators\RedisShardLocator;

/**
 * @deprecated since 4.3.0. The Redis locator implementation lives at
 *             {@see \Laravel\RedisShard\Locators\RedisShardLocator}; this
 *             root-namespace alias remains for backward compatibility and
 *             will be removed in 5.0.
 */
class ShardLocator extends RedisShardLocator
{
}
