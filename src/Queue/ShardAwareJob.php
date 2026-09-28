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

    /**
     * Dispatch this job with shard affinity captured from a model.
     */
    public static function dispatchSharded(Model $model, mixed ...$arguments): \Illuminate\Foundation\Bus\PendingDispatch
    {
        /** @var static $job */
        $job = app()->make(static::class, $arguments);
        $job->setShardContext(ShardContextDispatcher::capture($model));

        return dispatch($job);
    }

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
