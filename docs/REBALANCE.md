# Rebalance Operations

Safe, resumable data movement between shards.

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

The default `DatabaseRebalanceDataMover` is safe to re-run:

1. Copy the row to the target shard (`updateOrInsert`).
2. Delete the row from the source shard.

Implications:

- **Interrupt after copy, before delete** — the row exists on both shards. Re-running the same move succeeds and deletes the source (copy is a no-op).
- **Re-run after a completed move** — source is gone, target has the row → success, no work done.
- **Source already gone and target missing** — treated as a failure so silent data loss is not ignored.

There is no cross-shard transaction. Prefer queued/idempotent jobs when moving large volumes.

### Write fencing

Between the copy and the delete, the mover re-reads the source row. If it
changed during the copy window (a concurrent write), the delete is **skipped**,
the latest row is propagated to the target, and the fence trip is counted. The
`shard:rebalance --format=json` summary reports it as `fenced_moves`; a later
quiet run performs the delete. A concurrent write can therefore never be lost
by a rebalance.

Disable with `REDIS_SHARD_REBALANCE_FENCE=false` (not recommended).

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
