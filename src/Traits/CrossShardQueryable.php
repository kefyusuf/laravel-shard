<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Traits;

use Laravel\RedisShard\Query\CrossShardQueryBuilder;

trait CrossShardQueryable
{
    /**
     * Create a new cross-shard query builder.
     *
     * @return CrossShardQueryBuilder
     */
    public static function crossShard(): CrossShardQueryBuilder
    {
        return new CrossShardQueryBuilder(static::class);
    }

    /**
     * Search across all shards with a simple query.
     *
     * @param string $column
     * @param mixed $value
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function searchAcrossShards(string $column, $value)
    {
        return static::crossShard()->where($column, $value)->get();
    }

    /**
     * Find a model across all shards by a specific column.
     *
     * @param string $column
     * @param mixed $value
     * @return static|null
     */
    public static function findAcrossShards(string $column, $value): ?static
    {
        return static::crossShard()->where($column, $value)->first();
    }

    /**
     * Count records across all shards.
     *
     * @return int
     */
    public static function countAcrossShards(): int
    {
        return static::crossShard()->count();
    }

    /**
     * Get aggregated statistics across all shards.
     *
     * @param string $column
     * @return array
     */
    public static function aggregateAcrossShards(string $column): array
    {
        $crossShard = static::crossShard();

        return [
            'count' => $crossShard->count(),
            'sum' => $crossShard->sum($column),
            'avg' => $crossShard->avg($column),
            'min' => $crossShard->min($column),
            'max' => $crossShard->max($column),
        ];
    }

    /**
     * Search with LIKE across all shards.
     *
     * @param string $column
     * @param string $pattern
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function searchLikeAcrossShards(string $column, string $pattern)
    {
        return static::crossShard()->whereLike($column, $pattern)->get();
    }

    /**
     * Get recent records across all shards.
     *
     * @param int $limit
     * @param string $dateColumn
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function recentAcrossShards(int $limit = 10, string $dateColumn = 'created_at')
    {
        return static::crossShard()
            ->orderBy($dateColumn, 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get paginated results across all shards.
     *
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public static function paginateAcrossShards(int $page = 1, int $perPage = 15): array
    {
        $offset = ($page - 1) * $perPage;
        $total = static::countAcrossShards();

        $results = static::crossShard()
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return [
            'data' => $results,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * Batch update across shards.
     *
     * @param array $conditions
     * @param array $updates
     * @return int Total number of updated records
     */
    public static function batchUpdateAcrossShards(array $conditions, array $updates): int
    {
        $shards = \Laravel\RedisShard\Facades\ShardManager::getAvailableShards();
        $totalUpdated = 0;

        foreach ($shards as $shard) {
            $query = static::on($shard)->newQuery();

            foreach ($conditions as $column => $value) {
                $query->where($column, $value);
            }

            $totalUpdated += $query->update($updates);
        }

        return $totalUpdated;
    }

    /**
     * Batch delete across shards.
     *
     * @param array $conditions
     * @return int Total number of deleted records
     */
    public static function batchDeleteAcrossShards(array $conditions): int
    {
        $shards = \Laravel\RedisShard\Facades\ShardManager::getAvailableShards();
        $totalDeleted = 0;

        foreach ($shards as $shard) {
            $query = static::on($shard)->newQuery();

            foreach ($conditions as $column => $value) {
                $query->where($column, $value);
            }

            $totalDeleted += $query->delete();
        }

        return $totalDeleted;
    }

    /**
     * Get distribution of records across shards.
     *
     * @return array
     */
    public static function getShardDistribution(): array
    {
        $shards = \Laravel\RedisShard\Facades\ShardManager::getAvailableShards();
        $distribution = [];
        $total = 0;

        foreach ($shards as $shard) {
            $count = static::on($shard)->count();
            $distribution[$shard] = $count;
            $total += $count;
        }

        // Add percentages
        foreach ($distribution as $shard => $count) {
            $distribution[$shard] = [
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            ];
        }

        return [
            'total' => $total,
            'shards' => $distribution,
            'shard_count' => count($shards),
            'average_per_shard' => $total > 0 ? round($total / count($shards), 2) : 0,
        ];
    }

    /**
     * Execute a custom callback on each shard.
     *
     * @param callable $callback
     * @return array
     */
    public static function executeOnAllShards(callable $callback): array
    {
        $shards = \Laravel\RedisShard\Facades\ShardManager::getAvailableShards();
        $results = [];

        foreach ($shards as $shard) {
            $model = static::query()->getModel()->newInstance();
            $model->setConnection($shard);
            $results[$shard] = $callback($model, $shard);
        }

        return $results;
    }
}
