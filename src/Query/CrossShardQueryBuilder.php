<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Facades\ShardManager;

class CrossShardQueryBuilder
{
    /**
     * @var class-string<Model>
     */
    protected string $modelClass;

    /**
     * @var array
     */
    protected array $wheres = [];

    /**
     * @var array
     */
    protected array $orders = [];

    /**
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * @var int
     */
    protected int $offset = 0;

    /**
     * @var array
     */
    protected array $selects = ['*'];

    /**
     * Create a new cross-shard query builder.
     *
     * @param class-string<Model> $modelClass
     */
    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * Add a where clause.
     *
     * @param string $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function where(string $column, $operator = null, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a where in clause.
     *
     * @param string $column
     * @param array $values
     * @return $this
     */
    public function whereIn(string $column, array $values): self
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
        ];

        return $this;
    }

    /**
     * Add a where like clause.
     *
     * @param string $column
     * @param string $value
     * @return $this
     */
    public function whereLike(string $column, string $value): self
    {
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add an order by clause.
     *
     * @param string $column
     * @param string $direction
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction),
        ];

        return $this;
    }

    /**
     * Set the limit.
     *
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the offset.
     *
     * @param int $offset
     * @return $this
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Set the select columns.
     *
     * @param array $columns
     * @return $this
     */
    public function select(array $columns): self
    {
        $this->selects = $columns;
        return $this;
    }

    /**
     * Execute the query and get results.
     *
     * @return Collection
     */
    public function get(): Collection
    {
        $shards = ShardManager::getAvailableShards();
        $results = new Collection();

        foreach ($shards as $shard) {
            $query = $this->buildShardQuery($shard);
            /** @var Collection<int, Model> $shardResults */
            $shardResults = $query->get();
            
            // Add shard information to each model
            foreach ($shardResults as $model) {
                $model->setAttribute('_shard', $shard);
            }
            
            $results = $results->merge($shardResults);
        }

        // Apply cross-shard ordering and limiting
        return $this->applyCrossShardOperations($results);
    }

    /**
     * Get the first result.
     *
     * @return Model|null
     */
    public function first(): ?Model
    {
        $this->limit(1);
        $results = $this->get();
        return $results->first();
    }

    /**
     * Count results across all shards.
     *
     * @return int
     */
    public function count(): int
    {
        $shards = ShardManager::getAvailableShards();
        $total = 0;

        foreach ($shards as $shard) {
            $query = $this->buildShardQuery($shard);
            $total += $query->count();
        }

        return $total;
    }

    /**
     * Get the sum of a column across all shards.
     *
     * @param string $column
     * @return float
     */
    public function sum(string $column): float
    {
        $shards = ShardManager::getAvailableShards();
        $total = 0;

        foreach ($shards as $shard) {
            $query = $this->buildShardQuery($shard);
            $total += $query->sum($column) ?: 0;
        }

        return $total;
    }

    /**
     * Get the average of a column across all shards.
     *
     * @param string $column
     * @return float
     */
    public function avg(string $column): float
    {
        $shards = ShardManager::getAvailableShards();
        $totalSum = 0;
        $totalCount = 0;

        foreach ($shards as $shard) {
            $query = $this->buildShardQuery($shard);
            $shardSum = $query->sum($column) ?: 0;
            $shardCount = $query->count();
            
            $totalSum += $shardSum;
            $totalCount += $shardCount;
        }

        return $totalCount > 0 ? $totalSum / $totalCount : 0;
    }

    /**
     * Get the maximum value of a column across all shards.
     *
     * @param string $column
     * @return mixed
     */
    public function max(string $column)
    {
        $shards = ShardManager::getAvailableShards();
        $max = null;

        foreach ($shards as $shard) {
            $query = $this->buildShardQuery($shard);
            $shardMax = $query->max($column);
            
            if ($max === null || $shardMax > $max) {
                $max = $shardMax;
            }
        }

        return $max;
    }

    /**
     * Get the minimum value of a column across all shards.
     *
     * @param string $column
     * @return mixed
     */
    public function min(string $column)
    {
        $shards = ShardManager::getAvailableShards();
        $min = null;

        foreach ($shards as $shard) {
            $query = $this->buildShardQuery($shard);
            $shardMin = $query->min($column);
            
            if ($min === null || $shardMin < $min) {
                $min = $shardMin;
            }
        }

        return $min;
    }

    /**
     * Build a query for a specific shard.
     *
     * @param string $shard
     * @return Builder
     */
    protected function buildShardQuery(string $shard): Builder
    {
        $model = new $this->modelClass();
        $query = $model->on($shard)->newQuery();

        // Apply select
        if ($this->selects !== ['*']) {
            $query->select($this->selects);
        }

        // Apply where clauses
        foreach ($this->wheres as $where) {
            switch ($where['type']) {
                case 'basic':
                    $query->where($where['column'], $where['operator'], $where['value']);
                    break;
                case 'in':
                    $query->whereIn($where['column'], $where['values']);
                    break;
                case 'like':
                    $query->where($where['column'], 'LIKE', $where['value']);
                    break;
            }
        }

        // Apply ordering
        foreach ($this->orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        // Bound per-shard result size so global merge has less data to process.
        if ($this->limit !== null) {
            $query->limit($this->offset + $this->limit);
        }

        return $query;
    }

    /**
     * Apply cross-shard operations like ordering and limiting.
     *
     * @param Collection $results
     * @return Collection
     */
    protected function applyCrossShardOperations(Collection $results): Collection
    {
        // Apply cross-shard ordering
        foreach (array_reverse($this->orders) as $order) {
            $results = $results->sortBy($order['column'], SORT_REGULAR, $order['direction'] === 'desc');
        }

        // Apply offset and limit
        if ($this->offset > 0) {
            $results = $results->skip($this->offset);
        }

        if ($this->limit !== null) {
            $results = $results->take($this->limit);
        }

        return $results->values();
    }
}
