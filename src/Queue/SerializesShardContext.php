<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Queue;

use Laravel\RedisShard\Exceptions\ShardingException;

/**
 * Captures and restores shard affinity on queued jobs so workers do not
 * silently write to the wrong shard.
 */
trait SerializesShardContext
{
    protected ?ShardContext $shardContext = null;

    public function getShardContext(): ?ShardContext
    {
        return $this->shardContext;
    }

    public function setShardContext(?ShardContext $context): void
    {
        $this->shardContext = $context;
    }

    /**
     * Bind the job's shard connection for the duration of the callback.
     *
     * @template TResult
     * @param callable(): TResult $callback
     * @return TResult
     */
    protected function withShardContext(callable $callback): mixed
    {
        if ($this->shardContext === null) {
            return $callback();
        }

        $connection = $this->shardContext->connection;
        $connections = config('redis_sharding.connections', []);

        if (!array_key_exists($connection, $connections)) {
            throw new ShardingException(sprintf(
                'Cannot restore shard context: connection "%s" is not configured.',
                $connection
            ));
        }

        if (app()->bound('request')) {
            app('request')->attributes->set('shard_connection', $connection);
        }

        return $callback();
    }
}
