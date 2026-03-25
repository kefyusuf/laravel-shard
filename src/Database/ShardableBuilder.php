<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Illuminate\Database\Eloquent\Builder;
use Laravel\RedisShard\Facades\ShardManager;

class ShardableBuilder extends Builder
{
    /**
     * Execute a query operation on a specific shard while preserving current builder constraints.
     *
     * @param string $shardConnection
     * @param callable $operation
     * @return mixed
     */
    protected function runOnShard(string $shardConnection, callable $operation): mixed
    {
        $query = $this->getQuery();
        $originalQueryConnection = $query->connection;
        $originalModelConnection = $this->model->getConnectionName();

        $this->model->setConnection($shardConnection);
        $query->connection = app('db')->connection($shardConnection);

        try {
            return $operation();
        } finally {
            $this->model->setConnection($originalModelConnection);
            $query->connection = $originalQueryConnection;
        }
    }

    /**
     * Build a query instance pinned to a specific shard connection.
     */
    protected function newQueryForShard(string $shardConnection): Builder
    {
        $model = clone $this->model;
        $model->setConnection($shardConnection);

        return $model->newQuery();
    }

    /**
     * Find a model by its primary key.
     *
     * @param mixed $id
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|static[]|static|null
     */
    public function find($id, $columns = ['*'])
    {
        if (is_array($id)) {
            return $this->findMany($id, $columns);
        }

        $model = $this->model;
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $shardKeyName = method_exists($model, 'getShardKeyName') ? $model->getShardKeyName() : $keyName;

        // If the shard key is the primary key, we can locate the shard
        if ($shardKeyName === $keyName) {
            $shardConnection = $this->resolveShardConnectionForKey($table, $id);

            if ($shardConnection !== null) {
                return $this->runOnShard(
                    $shardConnection,
                    fn () => parent::find($id, $columns)
                );
            }
        }

        return parent::find($id, $columns);
    }

    /**
     * Find multiple models by their primary keys.
     *
     * @param \Illuminate\Contracts\Support\Arrayable|array $ids
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findMany($ids, $columns = ['*'])
    {
        if (empty($ids)) {
            return $this->model->newCollection();
        }

        // Group IDs by shard
        $shardGroups = [];
        $model = $this->model;
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $shardKeyName = method_exists($model, 'getShardKeyName') ? $model->getShardKeyName() : $keyName;

        // If the shard key is the primary key, we can locate the shard for each ID
        if ($shardKeyName === $keyName) {
            foreach ($ids as $id) {
                $shardConnection = $this->resolveShardConnectionForKey($table, $id);
                
                if ($shardConnection === null) {
                    // If we don't know the shard, we'll need to check all shards
                    $shardGroups['unknown'][] = $id;
                } else {
                    $shardGroups[$shardConnection][] = $id;
                }
            }
        } else {
            // If the shard key is not the primary key, we need to check all shards
            $shardGroups['unknown'] = $ids;
        }

        $results = $this->model->newCollection();

        // Query each shard for its respective IDs
        foreach ($shardGroups as $shardConnection => $shardIds) {
            if ($shardConnection !== 'unknown') {
                $query = $this->newQueryForShard($shardConnection);
                $shardResults = $query->whereIn($this->model->getQualifiedKeyName(), $shardIds)
                    ->get($columns);

                $results = $results->merge($shardResults);
                continue;
            }

            foreach (ShardManager::getAvailableShards() as $availableShard) {
                $query = $this->newQueryForShard($availableShard);
                $shardResults = $query->whereIn($this->model->getQualifiedKeyName(), $shardIds)
                    ->get($columns);

                $results = $results->merge($shardResults);
            }
        }

        return $results->unique($this->model->getKeyName())->values();
    }

    /**
     * Execute the query and get the first result.
     *
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Model|object|static|null
     */
    public function first($columns = ['*'])
    {
        // If we have a where clause on the shard key, we can locate the shard
        $model = $this->model;
        $shardKeyName = method_exists($model, 'getShardKeyName') ? $model->getShardKeyName() : $model->getKeyName();
        $table = $model->getTable();

        $wheres = $this->getQuery()->wheres;
        
        foreach ($wheres as $where) {
            $column = isset($where['column']) ? (string) $where['column'] : '';
            $normalizedColumn = str_contains($column, '.') ? (string) substr(strrchr($column, '.'), 1) : $column;

            if (
                $normalizedColumn === $shardKeyName
                && ($where['type'] ?? null) === 'Basic'
                && ($where['operator'] ?? null) === '='
            ) {
                $shardKeyValue = $where['value'];
                $shardConnection = $this->resolveShardConnectionForKey($table, $shardKeyValue);

                if ($shardConnection !== null) {
                    return $this->runOnShard(
                        $shardConnection,
                        fn () => parent::first($columns)
                    );
                }
            }
        }

        return parent::first($columns);
    }

    protected function resolveShardConnectionForKey(string $table, mixed $key): ?string
    {
        $shardConnection = app('shard.locator')->locate($table, $key);
        if (is_string($shardConnection) && $shardConnection !== '') {
            return $shardConnection;
        }

        try {
            return ShardManager::getShardConnection($table, $key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
