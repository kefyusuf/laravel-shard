<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Queue;

use Illuminate\Contracts\Queue\Job;
use Laravel\RedisShard\Exceptions\ShardingException;

/**
 * Job middleware that rebinds the shard connection before the handler runs.
 *
 * Usage:
 *  public function middleware(): array
 *  {
 *      return [new RestoreShardContext()];
 *  }
 */
class RestoreShardContext
{
    public function handle(Job $job, callable $next): mixed
    {
        $payload = $job->payload();
        $raw = $payload['shard_context'] ?? null;

        if (is_array($raw)) {
            $context = ShardContext::fromArray($raw);

            if ($context !== null) {
                $this->apply($context);
            }
        }

        return $next($job);
    }

    public function apply(ShardContext $context): void
    {
        $connections = config('redis_sharding.connections', []);

        if (!array_key_exists($context->connection, $connections)) {
            throw new ShardingException(sprintf(
                'Cannot restore shard context: connection "%s" is not configured.',
                $context->connection
            ));
        }

        if (app()->bound('request')) {
            app('request')->attributes->set('shard_connection', $context->connection);
        }

        if ($context->key !== null) {
            app('shard.locator')->register($context->table, $context->key, $context->connection);
        }
    }
}
