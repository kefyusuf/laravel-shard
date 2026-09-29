<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Queue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Traits\Shardable;

/**
 * Attaches shard affinity to outgoing queued payloads so workers can restore it.
 */
class ShardContextDispatcher
{
    public function boot(): void
    {
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            $context = self::currentContext();

            if ($context === null) {
                return [];
            }

            return ['shard_context' => $context->toArray()];
        });
    }

    public static function capture(Model $model): ShardContext
    {
        if (! in_array(Shardable::class, class_uses_recursive($model), true)) {
            throw new ShardingException(sprintf(
                'Cannot capture shard context from %s: model is not Shardable.',
                $model::class
            ));
        }

        $connection = $model->getConnectionName();

        if ($connection === null || $connection === '') {
            throw new ShardingException(sprintf(
                'Cannot capture shard context from %s: no shard connection resolved.',
                $model::class
            ));
        }

        $key = method_exists($model, 'getShardKeyValue')
            ? $model->getShardKeyValue()
            : $model->getKey();

        return new ShardContext(
            table: $model->getTable(),
            key: $key,
            connection: $connection,
            shardKey: method_exists($model, 'getShardKeyName') ? $model->getShardKeyName() : null,
        );
    }

    protected static function currentContext(): ?ShardContext
    {
        if (! app()->bound('request')) {
            return null;
        }

        $connection = app('request')->attributes->get('shard_connection');

        if (! is_string($connection) || $connection === '') {
            return null;
        }

        return new ShardContext(
            table: '*',
            key: null,
            connection: $connection,
        );
    }
}
