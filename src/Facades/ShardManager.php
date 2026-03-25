<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getShardConnection(string $table, mixed $key)
 * @method static array getAvailableShards()
 * @method static bool createShard(string $name, array $config)
 * @method static \Laravel\RedisShard\Contracts\ShardStrategyInterface strategy(?string $name = null)
 * @method static \Illuminate\Support\Collection strategies()
 *
 * @see \Laravel\RedisShard\ShardManager
 */
class ShardManager extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'shard.manager';
    }
}
