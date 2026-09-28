<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Rebalance;

use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;

/**
 * Copies a row to the target shard, then deletes it from the source.
 *
 * The operation is idempotent: re-running after a successful move is a no-op
 * success. Interruptions that leave a row on both shards are recoverable by
 * re-running the same command.
 */
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

        $targetRow = DB::connection($toShard)
            ->table($table)
            ->where($keyColumn, $key)
            ->first();

        $sourceRow = DB::connection($fromShard)
            ->table($table)
            ->where($keyColumn, $key)
            ->first();

        // Already on the target and gone from the source: nothing to do.
        if ($sourceRow === null) {
            return $targetRow !== null;
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
    }

    protected function resolveKeyColumn(string $table): string
    {
        return $this->tableKeyColumns[$table] ?? 'id';
    }
}
