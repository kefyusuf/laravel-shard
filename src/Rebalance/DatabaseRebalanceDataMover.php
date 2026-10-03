<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Rebalance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;
use Laravel\RedisShard\Exceptions\ShardingException;

/**
 * Copies a row to the target shard, then deletes it from the source.
 *
 * The operation is idempotent: re-running after a successful move is a no-op
 * success. Interruptions that leave a row on both shards are recoverable by
 * re-running the same command.
 *
 * With fencing enabled, a source delete requires the copied snapshot to
 * match in a single conditional DELETE. Changed rows remain on the source
 * and the latest observed version is copied to the target. This is not an
 * online resharding protocol or a distributed transaction.
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
        $shardConfig = app(\Laravel\RedisShard\Support\RedisShardConfig::class);
        $this->tableKeyColumns = $shardConfig->rebalanceTableKeyColumns();
        $this->deleteSourceAfterCopy = $shardConfig->rebalanceDeleteSourceAfterCopy();
        $this->fenceEnabled = $shardConfig->rebalanceFenceEnabled();
    }

    public function move(string $table, mixed $key, string $fromShard, string $toShard): bool
    {
        $keyColumn = $this->resolveKeyColumn($table);

        $targetRow = DB::connection($toShard)
            ->table($table)
            ->where($keyColumn, $key)
            ->first();

        $sourceRows = DB::connection($fromShard)
            ->table($table)
            ->where($keyColumn, $key)
            ->limit(2)
            ->get();

        if ($sourceRows->count() > 1) {
            throw new ShardingException(sprintf(
                'Cannot rebalance "%s": key column "%s" matches multiple source rows. A unique key is required.',
                $table,
                $keyColumn
            ));
        }

        $sourceRow = $sourceRows->first();

        // Already on the target and gone from the source: nothing to do.
        if ($sourceRow === null) {
            return $targetRow !== null;
        }

        $payload = (array) $sourceRow;
        $keyValue = $payload[$keyColumn] ?? $key;

        $delete = DB::connection($fromShard)->table($table)->where($keyColumn, $keyValue);
        if ($this->deleteSourceAfterCopy && $this->fenceEnabled) {
            // Build and validate the snapshot predicates before copying.
            $this->constrainToSnapshot($delete, $payload);
        }

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
                // require an explicit source reconciliation before cleanup.
                $this->copyRow($toShard, $table, $keyColumn, $keyValue, (array) $currentRow);
                $this->fencedMoves++;

                return true;
            }

            $deleted = $delete->delete();

            if ($this->fenceEnabled && $deleted === 0) {
                $latestRow = DB::connection($fromShard)
                    ->table($table)
                    ->where($keyColumn, $keyValue)
                    ->first();

                if ($latestRow !== null) {
                    $this->copyRow($toShard, $table, $keyColumn, $keyValue, (array) $latestRow);
                    $this->fencedMoves++;
                }
            }
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
     * Compare the snapshot and delete in one statement, without allowing a
     * case-insensitive collation to hide changed string values.
     *
     * @param array<string, mixed> $payload
     */
    protected function constrainToSnapshot(Builder $query, array $payload): void
    {
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $query->getConnection();
        $driver = $connection->getDriverName();
        foreach ($payload as $column => $value) {
            if (! is_string($value)) {
                $query->where($column, $value);

                continue;
            }

            $wrapped = $query->getGrammar()->wrap($column);
            $comparison = match ($driver) {
                'sqlite' => "CAST({$wrapped} AS BLOB) = CAST(? AS BLOB)",
                'mysql', 'mariadb' => "CAST({$wrapped} AS BINARY) = CAST(? AS BINARY)",
                'pgsql' => "convert_to(CAST({$wrapped} AS text), 'UTF8') = convert_to(CAST(? AS text), 'UTF8')",
                default => throw new ShardingException(sprintf(
                    'Snapshot string comparison is not supported for driver "%s". Use a custom rebalance data mover.',
                    $driver
                )),
            };
            $query->whereRaw($comparison, [$value]);
        }
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
