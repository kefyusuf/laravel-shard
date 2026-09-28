<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Queue;

use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Exceptions\ShardingException;

/**
 * Base helper for jobs that must run against a specific shard.
 */
abstract class ShardAwareJob implements ShardAwareInterface
{
    use SerializesShardContext;

    public function middleware(): array
    {
        return [new RestoreShardContext()];
    }

    protected function shardContextFrom(Model $model): ShardContext
    {
        return ShardContextDispatcher::capture($model);
    }

    /**
     * Ensure the job has a shard context before handling.
     */
    protected function requireShardContext(): ShardContext
    {
        if ($this->shardContext === null) {
            throw new ShardingException(sprintf(
                '%s requires a shard context. Dispatch via dispatchSharded() or set setShardContext().',
                static::class
            ));
        }

        return $this->shardContext;
    }
}
