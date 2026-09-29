<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\LazyCollection;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Facades\ShardManager;

class ShardableBuilder extends Builder
{
    protected bool $bypassShardRouting = false;

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
        $originalBypassState = $this->bypassShardRouting;

        $this->model->setConnection($shardConnection);
        $query->connection = app('db')->connection($shardConnection);
        $this->bypassShardRouting = true;

        try {
            return $operation();
        } finally {
            $this->model->setConnection($originalModelConnection);
            $query->connection = $originalQueryConnection;
            $this->bypassShardRouting = $originalBypassState;
        }
    }

    /**
     * Execute a read operation on a shard, preferring its configured read replica.
     *
     * @param string $shardConnection
     * @param callable $operation
     * @return mixed
     */
    protected function runOnShardRead(string $shardConnection, callable $operation): mixed
    {
        return $this->runOnShard($this->readConnectionFor($shardConnection), $operation);
    }

    protected function readConnectionFor(string $shardConnection): string
    {
        return app(\Laravel\RedisShard\Database\ReadReplicaResolver::class)->resolve($shardConnection);
    }

    protected function cloneForShardRead(string $shardConnection): self
    {
        return $this->cloneForShard($this->readConnectionFor($shardConnection));
    }

    protected function buildGroupedShardReadQuery(string $shardConnection, array $values): self
    {
        return $this->buildGroupedShardQuery($this->readConnectionFor($shardConnection), $values);
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

    protected function cloneForShard(string $shardConnection): self
    {
        $clone = clone $this;
        $clone->model = clone $this->model;
        $clone->model->setConnection($shardConnection);
        $clone->query->connection = app('db')->connection($shardConnection);
        $clone->bypassShardRouting = true;

        return $clone;
    }

    protected function getShardKeyName(): string
    {
        return method_exists($this->model, 'getShardKeyName')
            ? $this->model->getShardKeyName()
            : $this->model->getKeyName();
    }

    protected function normalizeColumn(mixed $column): ?string
    {
        if (! is_string($column) || $column === '') {
            return null;
        }

        return str_contains($column, '.') ? (string) substr(strrchr($column, '.'), 1) : $column;
    }

    /**
     * @return array{mode:string,connection?:string,groups?:array<string, array<int, mixed>>,reason?:string}
     */
    protected function resolveRoutingPlan(): array
    {
        $explicitConnection = $this->model->getConnectionName();
        $defaultConnection = config('database.default');

        if (is_string($explicitConnection) && $explicitConnection !== '' && $explicitConnection !== $defaultConnection) {
            return [
                'mode' => 'single',
                'connection' => $explicitConnection,
            ];
        }

        $wheres = $this->getQuery()->wheres;
        $shardKeyName = $this->getShardKeyName();
        $table = $this->model->getTable();

        foreach ($wheres as $where) {
            $column = $this->normalizeColumn($where['column'] ?? null);

            if ($column !== $shardKeyName) {
                continue;
            }

            if (($where['boolean'] ?? 'and') !== 'and') {
                return [
                    'mode' => 'unsupported',
                    'reason' => 'Shard key constraints combined with OR predicates are not routable safely.',
                ];
            }

            $type = $where['type'] ?? null;

            if ($type === 'Basic' && ($where['operator'] ?? null) === '=') {
                $connection = $this->resolveShardConnectionForKey($table, $where['value'] ?? null);

                return $connection !== null
                    ? ['mode' => 'single', 'connection' => $connection]
                    : ['mode' => 'unsupported', 'reason' => 'Unable to resolve shard for the shard key constraint.'];
            }

            if (in_array($type, ['In', 'InRaw', 'IntegerInRaw'], true)) {
                $values = array_values(array_unique(array_map(static fn (mixed $value): string => (string) $value, $where['values'] ?? [])));

                if ($values === []) {
                    return ['mode' => 'none'];
                }

                $groups = [];

                foreach ($values as $value) {
                    $connection = $this->resolveShardConnectionForKey($table, $value);

                    if ($connection === null) {
                        return [
                            'mode' => 'unsupported',
                            'reason' => 'Unable to resolve every shard key in the whereIn constraint.',
                        ];
                    }

                    $groups[$connection][] = $value;
                }

                if (count($groups) === 1) {
                    return ['mode' => 'single', 'connection' => array_key_first($groups)];
                }

                return ['mode' => 'many', 'groups' => $groups];
            }

            return [
                'mode' => 'unsupported',
                'reason' => sprintf('Shard key predicate type "%s" is not supported for implicit routing.', (string) $type),
            ];
        }

        return ['mode' => 'none'];
    }

    protected function requireRoutingPlan(string $operation): array
    {
        $plan = $this->resolveRoutingPlan();

        if (in_array($plan['mode'], ['single', 'many'], true)) {
            return $plan;
        }

        $reason = $plan['reason'] ?? 'Provide an explicit shard-bound connection or shard key predicate.';

        throw new ShardingException(sprintf(
            'Cannot safely %s on sharded table "%s" without deterministic shard routing. %s',
            $operation,
            $this->model->getTable(),
            $reason
        ));
    }

    protected function buildGroupedShardQuery(string $shardConnection, array $values): self
    {
        $builder = $this->cloneForShard($shardConnection);
        $builder->whereIn($this->model->qualifyColumn($this->getShardKeyName()), $values);

        return $builder;
    }

    protected function executeGroupedReads(array $groups, callable $operation): Collection
    {
        $results = $this->model->newCollection();

        foreach ($groups as $connection => $values) {
            $builder = $this->buildGroupedShardReadQuery($connection, $values);
            $shardResults = $operation($builder);

            if ($shardResults instanceof Collection) {
                $results = $results->merge($shardResults);
            }
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeUpsertRows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return array_is_list($values) && is_array($values[0] ?? null)
            ? $values
            : [$values];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function groupUpsertRowsByShard(array $rows): array
    {
        $groups = [];
        $shardKeyName = $this->getShardKeyName();
        $table = $this->model->getTable();

        foreach ($rows as $row) {
            if (! array_key_exists($shardKeyName, $row)) {
                throw new ShardingException(sprintf(
                    'Cannot safely upsert into sharded table "%s" without the shard key column "%s" in every row.',
                    $table,
                    $shardKeyName
                ));
            }

            $connection = $this->resolveShardConnectionForKey($table, $row[$shardKeyName]);

            if ($connection === null) {
                throw new ShardingException(sprintf(
                    'Cannot safely upsert into sharded table "%s" because the shard key value for "%s" could not be resolved.',
                    $table,
                    $shardKeyName
                ));
            }

            $groups[$connection][] = $row;
        }

        return $groups;
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
        if ($this->bypassShardRouting) {
            return parent::find($id, $columns);
        }

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
                return $this->runOnShardRead(
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
        if ($this->bypassShardRouting) {
            return parent::findMany($ids, $columns);
        }

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
                $query = $this->newQueryForShard($this->readConnectionFor($shardConnection));
                /** @var \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $shardResults */
                $shardResults = $query->whereIn($this->model->getQualifiedKeyName(), $shardIds)
                    ->get($columns);
                $results = $results->merge($shardResults);

                continue;
            }

            foreach (ShardManager::getAvailableShards() as $availableShard) {
                $query = $this->newQueryForShard($this->readConnectionFor($availableShard));
                /** @var \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $shardResults */
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
        if ($this->bypassShardRouting) {
            return parent::first($columns);
        }

        $plan = $this->requireRoutingPlan('select the first matching record');

        if ($plan['mode'] === 'single') {
            return $this->runOnShardRead(
                $plan['connection'],
                fn () => parent::first($columns)
            );
        }

        return $this->executeGroupedReads(
            $plan['groups'],
            fn (self $builder): Collection => $builder->get($columns)
        )->first();
    }

    public function get($columns = ['*'])
    {
        if ($this->bypassShardRouting) {
            return parent::get($columns);
        }

        $plan = $this->requireRoutingPlan('read records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShardRead($plan['connection'], fn () => parent::get($columns));
        }

        return $this->executeGroupedReads(
            $plan['groups'],
            fn (self $builder): Collection => $builder->get($columns)
        )->unique($this->model->getKeyName())->values();
    }

    public function count($columns = '*'): int
    {
        if ($this->bypassShardRouting) {
            return (int) $this->toBase()->count($columns);
        }

        $plan = $this->requireRoutingPlan('count records');

        if ($plan['mode'] === 'single') {
            return (int) $this->runOnShardRead($plan['connection'], fn (): int => (int) $this->toBase()->count($columns));
        }

        $count = 0;

        foreach ($plan['groups'] as $connection => $values) {
            $count += $this->buildGroupedShardReadQuery($connection, $values)->count($columns);
        }

        return $count;
    }

    public function exists(): bool
    {
        if ($this->bypassShardRouting) {
            return $this->toBase()->exists();
        }

        $plan = $this->requireRoutingPlan('check record existence');

        if ($plan['mode'] === 'single') {
            return (bool) $this->runOnShardRead($plan['connection'], fn (): bool => $this->toBase()->exists());
        }

        foreach ($plan['groups'] as $connection => $values) {
            if ($this->buildGroupedShardReadQuery($connection, $values)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function pluck($column, $key = null)
    {
        if ($this->bypassShardRouting) {
            return parent::pluck($column, $key);
        }

        $plan = $this->requireRoutingPlan('pluck records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShardRead($plan['connection'], fn () => parent::pluck($column, $key));
        }

        $results = new BaseCollection();

        foreach ($plan['groups'] as $connection => $values) {
            $results = $results->merge($this->buildGroupedShardReadQuery($connection, $values)->pluck($column, $key));
        }

        return $results->values();
    }

    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        if ($this->bypassShardRouting) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        $plan = $this->requireRoutingPlan('paginate records');

        $perPage = $perPage ?: $this->model->getPerPage();
        $page = $page ?: LengthAwarePaginator::resolveCurrentPage($pageName);

        if ($plan['mode'] === 'single') {
            $totalRecords = $this->cloneForShardRead($plan['connection'])->count();
            $items = $totalRecords > 0
                ? $this->cloneForShardRead($plan['connection'])->forPage($page, $perPage)->get($columns)
                : $this->model->newCollection();

            return new LengthAwarePaginator(
                $items,
                $totalRecords,
                $perPage,
                $page,
                [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => $pageName,
                ]
            );
        }

        $results = $this->get($columns);
        $items = $results->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $results->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    public function chunk($count, callable $callback)
    {
        if ($this->bypassShardRouting) {
            return parent::chunk($count, $callback);
        }

        $plan = $this->requireRoutingPlan('chunk records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShardRead($plan['connection'], fn () => parent::chunk($count, $callback));
        }

        foreach ($plan['groups'] as $connection => $values) {
            $continue = $this->buildGroupedShardReadQuery($connection, $values)->chunk($count, $callback);

            if ($continue === false) {
                return false;
            }
        }

        return true;
    }

    public function cursor()
    {
        if ($this->bypassShardRouting) {
            return parent::cursor();
        }

        $plan = $this->requireRoutingPlan('stream records');

        if ($plan['mode'] === 'single') {
            return $this->cloneForShardRead($plan['connection'])->cursor();
        }

        return LazyCollection::make(function () use ($plan) {
            foreach ($plan['groups'] as $connection => $groupValues) {
                foreach ($this->buildGroupedShardReadQuery($connection, $groupValues)->cursor() as $record) {
                    yield $record;
                }
            }
        });
    }

    public function update(array $values)
    {
        if ($this->bypassShardRouting) {
            return parent::update($values);
        }

        $plan = $this->requireRoutingPlan('update records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShard($plan['connection'], fn () => parent::update($values));
        }

        $updated = 0;

        foreach ($plan['groups'] as $connection => $groupValues) {
            $updated += (int) $this->buildGroupedShardQuery($connection, $groupValues)->update($values);
        }

        return $updated;
    }

    public function delete()
    {
        if ($this->bypassShardRouting) {
            return parent::delete();
        }

        $plan = $this->requireRoutingPlan('delete records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShard($plan['connection'], fn () => parent::delete());
        }

        $deleted = 0;

        foreach ($plan['groups'] as $connection => $groupValues) {
            $deleted += (int) $this->buildGroupedShardQuery($connection, $groupValues)->delete();
        }

        return $deleted;
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        if ($this->bypassShardRouting) {
            return parent::increment($column, $amount, $extra);
        }

        $plan = $this->requireRoutingPlan('increment records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShard($plan['connection'], fn () => parent::increment($column, $amount, $extra));
        }

        $updated = 0;

        foreach ($plan['groups'] as $connection => $groupValues) {
            $updated += (int) $this->buildGroupedShardQuery($connection, $groupValues)->increment($column, $amount, $extra);
        }

        return $updated;
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        if ($this->bypassShardRouting) {
            return parent::decrement($column, $amount, $extra);
        }

        $plan = $this->requireRoutingPlan('decrement records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShard($plan['connection'], fn () => parent::decrement($column, $amount, $extra));
        }

        $updated = 0;

        foreach ($plan['groups'] as $connection => $groupValues) {
            $updated += (int) $this->buildGroupedShardQuery($connection, $groupValues)->decrement($column, $amount, $extra);
        }

        return $updated;
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        if ($this->bypassShardRouting) {
            return parent::incrementEach($columns, $extra);
        }

        $plan = $this->requireRoutingPlan('increment records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShard($plan['connection'], fn () => parent::incrementEach($columns, $extra));
        }

        $updated = 0;

        foreach ($plan['groups'] as $connection => $groupValues) {
            $updated += (int) $this->buildGroupedShardQuery($connection, $groupValues)->incrementEach($columns, $extra);
        }

        return $updated;
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if ($this->bypassShardRouting) {
            return parent::upsert($values, $uniqueBy, $update);
        }

        $rows = $this->normalizeUpsertRows($values);

        if ($rows === []) {
            return parent::upsert($values, $uniqueBy, $update);
        }

        $explicitConnection = $this->model->getConnectionName();
        $defaultConnection = config('database.default');

        if (is_string($explicitConnection) && $explicitConnection !== '' && $explicitConnection !== $defaultConnection) {
            return $this->runOnShard($explicitConnection, fn () => parent::upsert($values, $uniqueBy, $update));
        }

        $groups = $this->groupUpsertRowsByShard($rows);

        if (count($groups) === 1) {
            return $this->runOnShard(
                array_key_first($groups),
                fn () => parent::upsert(array_values($rows), $uniqueBy, $update)
            );
        }

        $affected = 0;

        foreach ($groups as $connection => $groupRows) {
            $affected += (int) $this->cloneForShard($connection)->upsert($groupRows, $uniqueBy, $update);
        }

        return $affected;
    }

    public function touch($column = null)
    {
        if ($this->bypassShardRouting) {
            return parent::touch($column);
        }

        $plan = $this->requireRoutingPlan('touch records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShard($plan['connection'], fn () => parent::touch($column));
        }

        $updated = 0;

        foreach ($plan['groups'] as $connection => $groupValues) {
            $updated += (int) $this->buildGroupedShardQuery($connection, $groupValues)->touch($column);
        }

        return $updated;
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        if ($this->bypassShardRouting) {
            return parent::decrementEach($columns, $extra);
        }

        $plan = $this->requireRoutingPlan('decrement records');

        if ($plan['mode'] === 'single') {
            return $this->runOnShard($plan['connection'], fn () => parent::decrementEach($columns, $extra));
        }

        $updated = 0;

        foreach ($plan['groups'] as $connection => $groupValues) {
            $updated += (int) $this->buildGroupedShardQuery($connection, $groupValues)->decrementEach($columns, $extra);
        }

        return $updated;
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
