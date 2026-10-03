# Rebalance Operations

Resumable row movement between shards, subject to the concurrency and routing limits below.

## Command

```bash
php artisan shard:rebalance {table} \
  [--dry-run] \
  [--force] \
  [--metadata-only] \
  [--limit=N] \
  [--format=table|json] \
  [--strategy=name]
```

| Option | Purpose |
| --- | --- |
| `--dry-run` | Compute the move plan only; nothing is written |
| `--force` | Skip the confirmation prompt |
| `--metadata-only` | Update shard mappings without moving table rows |
| `--limit=N` | Perform at most N moves this run (gradual rebalance) |
| `--format=json` | Machine-readable summary + move list |

## Recommended workflow

1. **Plan** — always start with a dry run:

   ```bash
   php artisan shard:rebalance users --dry-run --format=json
   ```

2. **Move gradually** — cap each run so production stays quiet:

   ```bash
   php artisan shard:rebalance users --limit=500 --force
   ```

3. **Repeat** — re-run the same command until `remaining_moves` is `0`.

4. **Verify** — `shard:status` / `shard:health` after the last batch.

## Idempotency

The default `DatabaseRebalanceDataMover` supports retries for a single row identified by a unique key:

1. Copy the row to the target shard (`updateOrInsert`).
2. With write fencing enabled, delete the source only when its column values still match the copied snapshot.

Configure a column with an actual database unique constraint in `rebalance.table_key_columns`. If more than one source row matches the key, the mover throws an exception before copying or deleting anything. This runtime check does not establish schema uniqueness or prevent a concurrent duplicate insert; the database constraint must enforce that requirement. The mover does not move a tenant's set of rows identified by a non-unique `tenant_id`.

Implications:

- **Interrupt after copy, before delete** — the row exists on both shards. Re-running the move copies the source again, which can overwrite target changes, then attempts the conditional source delete.
- **Re-run after a completed move** — source is gone, target has the row → success, no work done.
- **Source already gone and target missing** — treated as a failure so silent data loss is not ignored.

There is no cross-shard transaction. Prefer queued/idempotent jobs when moving large volumes.

### Write fencing

Between the copy and the delete, the mover re-reads the source row. If it
changed during the copy window, the delete is skipped, the latest observed
row is propagated to the target, and the fence trip is counted. If the re-read
matches, deletion uses a single conditional `DELETE` with comparison predicates
for all copied columns. Strings are compared bytewise so a case-insensitive
collation cannot hide a change such as `Alice` to `ALICE`: SQLite uses `CAST AS BLOB`,
MySQL/MariaDB uses `CAST AS BINARY`, and PostgreSQL uses
`convert_to(CAST AS text, 'UTF8')`. Numeric values use SQL equality and null values
use `IS NULL`. A change after the re-read prevents deletion when these predicates
no longer match. When source deletion and fencing are enabled, a string snapshot
on an unsupported driver causes an exception before copying.

SQLite uses this same conditional `DELETE`; it does not use `SELECT ... FOR UPDATE`
or claim row-level locking. Database write serialization governs concurrent
statements. SQLite regression coverage includes a write from another connection
immediately before deletion, a null column, and a case-only change on a `NOCASE`
column. It does not establish behavior for every production database or column
type. Binary/blob columns and other unsupported types may require a custom mover.

The `shard:rebalance --format=json` summary reports fence trips as `fenced_moves`.
A fence retains the source row, but the command can still update the key's mapping
to the target. Later writes to either copy and retries that overwrite the target
remain outside this protection. Quiesce application writes during moves, inspect
both copies and the mapping after a fence, and reconcile them before deleting
retained source data. A later run only removes the source when its snapshot is
unchanged at deletion time. This is not a guarantee of lossless online resharding
or atomic copy, delete, and metadata updates across shards.

Disable with `REDIS_SHARD_REBALANCE_FENCE=false` (not recommended).

## Virtual buckets

With the `virtual_bucket` strategy a key always hashes into a **fixed bucket**
(`crc32(table:key) % N`, N configurable via `redis_sharding.virtual_buckets.count`,
default 1024), and a persistent **bucket → shard** map (stored in the shard
registry) decides where each bucket lives. Buckets are assigned on first touch
and never move implicitly when shards are added or removed — operators move
buckets explicitly:

```bash
php artisan shard:bucket-status          # buckets per shard, unassigned count
php artisan shard:bucket 512 shard2      # assign bucket 512 to shard2
php artisan shard:rebalance users        # moves only the keys of bucket 512
```

Because the rebalance planner relocates exactly the keys whose resolved shard
differs from the current placement, moving one bucket out of N touches only
~1/N of the keys — instead of the ~half the keys a consistent-hashing ring
rehash typically displaces. The bucket map is versioned inside the shard
registry file; legacy registry files keep working.

## Custom movers

Bind your own implementation when you need chunking, throttling, or hooks:

```php
// AppServiceProvider::register()
$this->app->singleton(
    \Laravel\RedisShard\Contracts\RebalanceDataMoverInterface::class,
    \App\Sharding\ChunkedRebalanceMover::class
);
```

Enable the bundled mover with:

```php
// config/redis_sharding.php
'rebalance' => [
    'enable_default_data_mover' => true,
    'delete_source_after_copy' => true,
    'table_key_columns' => [
        'users' => 'id',
    ],
],
```

## Progress

Table format shows a progress bar over the planned moves. JSON format returns:

```json
{
  "summary": {
    "status": "completed",
    "total_moves": 42,
    "planned_moves_all": 120,
    "successful_moves": 42,
    "failed_moves": 0,
    "limited": true,
    "remaining_moves": 78
  }
}
```

Use `remaining_moves` to drive looping in scripts.
