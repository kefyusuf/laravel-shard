<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Locators;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\RedisManager;
use Laravel\RedisShard\ShardLocator as LegacyRedisShardLocator;

/**
 * Redis-backed persistent shard map (redis module).
 *
 * This class is the stable name for the redis module implementation.
 * The legacy {@see \Laravel\RedisShard\ShardLocator} remains as a subclass
 * alias for backward compatibility.
 */
class RedisShardLocator extends LegacyRedisShardLocator
{
    public function __construct(
        RedisManager $redis,
        Repository $config,
        ?CacheFactory $cacheFactory = null
    ) {
        parent::__construct($redis, $config, $cacheFactory);
    }
}
