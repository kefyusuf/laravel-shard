<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Pinned-connection execution mechanics for shard-aware queries.
 *
 * Owns the swap-restore cycle (model + query connection pointed at a shard
 * for the duration of an operation) and read-replica selection, so the query
 * builder only expresses *what* to run, never *how* connections are pinned.
 */
class ShardExecutor
{
    public function __construct(
        protected DatabaseManager $db,
        protected ReadReplicaResolver $replicas
    ) {
    }

    /**
     * Run an operation with the model and query pinned to a shard connection;
     * both are restored afterwards, even on failure.
     *
     * @param Model $model
     * @param QueryBuilder $query the base query builder (Eloquent Builder's getQuery())
     * @param callable(): mixed $operation
     * @return mixed
     */
    public function run(string $shardConnection, Model $model, QueryBuilder $query, callable $operation): mixed
    {
        $originalQueryConnection = $query->connection;
        $originalModelConnection = $model->getConnectionName();

        $model->setConnection($shardConnection);
        $query->connection = $this->db->connection($shardConnection);

        try {
            return $operation();
        } finally {
            $model->setConnection($originalModelConnection);
            $query->connection = $originalQueryConnection;
        }
    }

    /**
     * Run a read operation, preferring the shard's configured read replica.
     *
     * @param Model $model
     * @param QueryBuilder $query the base query builder (Eloquent Builder's getQuery())
     * @param callable(): mixed $operation
     * @return mixed
     */
    public function runRead(string $shardConnection, Model $model, QueryBuilder $query, callable $operation): mixed
    {
        return $this->run($this->connectionForRead($shardConnection), $model, $query, $operation);
    }

    /**
     * The connection a read against this shard should use.
     */
    public function connectionForRead(string $shardConnection): string
    {
        return $this->replicas->resolve($shardConnection);
    }
}
