<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Rebalance;

use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;

class DatabaseRebalanceDataMover implements RebalanceDataMoverInterface
{
    /**
     * @var array<string, string>
     */
    protected array $tableKeyColumns;

    protected bool $deleteSourceAfterCopy;

    public function __construct()
    {
        $this->tableKeyColumns = (array) config('redis_sharding.rebalance.table_key_columns', []);
        $this->deleteSourceAfterCopy = (bool) config('redis_sharding.rebalance.delete_source_after_copy', true);
    }

    public function move(string $table, mixed $key, string $fromShard, string $toShard): bool
    {
        $keyColumn = $this->resolveKeyColumn($table);

        return DB::connection($fromShard)->transaction(function () use ($table, $key, $fromShard, $toShard, $keyColumn) {
            $sourceRow = DB::connection($fromShard)
                ->table($table)
                ->where($keyColumn, $key)
                ->first();

            if ($sourceRow === null) {
                return false;
            }

            $payload = (array) $sourceRow;
            $keyValue = $payload[$keyColumn] ?? $key;

            DB::connection($toShard)->table($table)->updateOrInsert(
                [$keyColumn => $keyValue],
                $payload
            );

            if ($this->deleteSourceAfterCopy) {
                DB::connection($fromShard)
                    ->table($table)
                    ->where($keyColumn, $key)
                    ->delete();
            }

            return true;
        });
    }

    protected function resolveKeyColumn(string $table): string
    {
        return $this->tableKeyColumns[$table] ?? 'id';
    }
}
