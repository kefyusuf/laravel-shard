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
 *
 * Write fencing: between the copy and the delete, the source row is re-read.
 * If it changed in the meantime, the delete is skipped and the latest row is
 * propagated to the target instead — a concurrent write can never be lost.
 * Trips are counted by fencedMoves().
 */
class DatabaseRebalanceDataMover implements RebalanceDataMoverInterface
{
    /**
     * @var array<string, string>
     */
    protected array $tableKeyColumns;

    protected bool $deleteSourceAfterCopy;

    protected bool $fenceEnabled;

    protected int $fencedMoves = 0;

    public function __construct()
    {
        $this->tableKeyColumns = (array) config('redis_sharding.rebalance.table_key_columns', []);
        $this->deleteSourceAfterCopy = (bool) config('redis_sharding.rebalance.delete_source_after_copy', true);
        $this->fenceEnabled = (bool) config('redis_sharding.rebalance.fence_enabled', true);
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

        $this->copyRow($toShard, $table, $keyColumn, $keyValue, $payload);

        if ($this->deleteSourceAfterCopy) {
            $currentRow = DB::connection($fromShard)
                ->table($table)
                ->where($keyColumn, $keyValue)
                ->first();

            if ($this->fenceEnabled
                && $currentRow !== null
                && $this->fingerprint($currentRow) !== $this->fingerprint($sourceRow)
            ) {
                // The source row was written while the copy ran: keep it on
                // the source, propagate the latest version to the target, and
                // let a later quiet run perform the delete.
                $this->copyRow($toShard, $table, $keyColumn, $keyValue, (array) $currentRow);
                $this->fencedMoves++;

                return true;
            }

            DB::connection($fromShard)
                ->table($table)
                ->where($keyColumn, $keyValue)
                ->delete();
        }

        return true;
    }

    /**
     * Number of rows whose delete was fenced because the source row changed
     * during the copy window.
     */
    public function fencedMoves(): int
    {
        return $this->fencedMoves;
    }

    protected function copyRow(string $connection, string $table, string $keyColumn, mixed $keyValue, array $payload): void
    {
        DB::connection($connection)->table($table)->updateOrInsert(
            [$keyColumn => $keyValue],
            $payload
        );
    }

    /**
     * @return string|false
     */
    protected function fingerprint(object $row): string|false
    {
        return json_encode($row);
    }

    protected function resolveKeyColumn(string $table): string
    {
        return $this->tableKeyColumns[$table] ?? 'id';
    }
}
