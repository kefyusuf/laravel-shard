<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Database\ShardableBuilder;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Facades\ShardManager;

/**
 * @mixin Model
 * @property bool $exists
 * @method static void creating(callable $callback)
 * @method static void saved(callable $callback)
 * @method static void deleted(callable $callback)
 * @method string getKeyName()
 * @method mixed getAttribute(string $key)
 * @method string getTable()
 * @method void setConnection(string $name)
 * @method string|null getConnectionName()
 */
trait Shardable
{
    use CrossShardQueryable;
    /**
     * The shard connection name.
     *
     * @var string|null
     */
    protected $shardConnection = null;

    /**
     * The shard key attribute.
     *
     * @var string|null
     */
    protected $shardKey = null;

    /**
     * Boot the shardable trait for a model.
     *
     * @return void
     */
    public static function bootShardable(): void
    {
        static::creating(function (Model $model) {
            $model->prepareShardConnectionForWrite();
        });

        static::saved(function (Model $model) {
            $model->registerWithShardLocator();
        });

        static::deleted(function (Model $model) {
            $model->removeFromShardLocator();
        });
    }

    /**
     * Get the shard key for this model.
     *
     * @return string
     */
    public function getShardKeyName(): string
    {
        return $this->shardKey ?? $this->getKeyName();
    }

    /**
     * Get the value of the shard key.
     *
     * @return mixed
     */
    public function getShardKeyValue(): mixed
    {
        return $this->getAttribute($this->getShardKeyName());
    }

    /**
     * Set the shard connection for this model.
     *
     * @param string|null $connection
     * @return $this
     */
    public function setShardConnection(?string $connection = null): self
    {
        if ($connection === null) {
            $table = $this->getTable();
            $key = $this->getShardKeyValue();

            if ($key !== null) {
                $connection = ShardManager::getShardConnection($table, $key);
            } else {
                $connection = $this->getRequestShardConnection();
            }
        }

        if ($connection !== null) {
            $this->shardConnection = $connection;
            $this->setConnection($connection);
        }

        return $this;
    }

    /**
     * Ensure shard connection is resolved before the insert query builder is created.
     */
    protected function prepareShardConnectionForWrite(): void
    {
        $defaultConnection = config('database.default');
        $explicitConnection = $this->getConnectionName();
        $hasExplicitNonDefaultConnection = is_string($explicitConnection)
            && $explicitConnection !== ''
            && $explicitConnection !== $defaultConnection;

        if ($this->shardConnection !== null || $hasExplicitNonDefaultConnection) {
            return;
        }

        $this->setShardConnection();

        $resolvedConnection = $this->getConnectionName();
        $resolvedToShard = is_string($resolvedConnection)
            && $resolvedConnection !== ''
            && $resolvedConnection !== $defaultConnection;

        if ($resolvedToShard) {
            return;
        }

        throw new ShardingException(sprintf(
            'Cannot resolve shard connection for new %s record before insert. Configure a shard key value, set an explicit shard connection, or bind a request shard connection.',
            $this->getTable()
        ));
    }

    /**
     * Persist the model while resolving shard connection up front for inserts.
     *
     * @param array<string, mixed> $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists === false) {
            $this->prepareShardConnectionForWrite();
        }

        return parent::save($options);
    }

    /**
     * Register this model with the shard locator.
     *
     * @return void
     */
    protected function registerWithShardLocator(): void
    {
        $table = $this->getTable();
        $key = $this->getShardKeyValue();
        $connection = $this->getConnectionName();

        if ($key !== null && $connection !== null) {
            app('shard.locator')->register($table, $key, $connection);
        }
    }

    /**
     * Remove this model from the shard locator.
     *
     * @return void
     */
    protected function removeFromShardLocator(): void
    {
        $table = $this->getTable();
        $key = $this->getShardKeyValue();

        if ($key !== null) {
            app('shard.locator')->forget($table, $key);
        }
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newEloquentBuilder($query): Builder
    {
        if ($this->shardConnection === null) {
            $requestShardConnection = $this->getRequestShardConnection();
            if ($requestShardConnection !== null) {
                $this->setConnection($requestShardConnection);
                $this->shardConnection = $requestShardConnection;
            }
        }

        return new ShardableBuilder($query);
    }

    protected function getRequestShardConnection(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $connection = app('request')->attributes->get('shard_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Create a new instance of the given model.
     *
     * @param array $attributes
     * @param bool $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false): self
    {
        $model = parent::newInstance($attributes, $exists);

        if ($this->shardConnection !== null) {
            $model->setConnection($this->shardConnection);
        }

        return $model;
    }
}
