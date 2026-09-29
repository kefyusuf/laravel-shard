<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Queue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Laravel\RedisShard\Exceptions\ShardingException;

/**
 * Dispatch a job with explicit shard affinity attached to the queue payload.
 */
function dispatchSharded(object $job, Model $model): PendingDispatch
{
    if (! $job instanceof ShardAwareInterface && ! in_array(SerializesShardContext::class, class_uses_recursive($job), true)) {
        throw new ShardingException(sprintf(
            'dispatchSharded() requires a shard-aware job, %s given.',
            $job::class
        ));
    }

    $context = ShardContextDispatcher::capture($model);

    $job->setShardContext($context);

    return dispatch($job);
}
