# Cross-Shard Transactions

Laravel's `DB::transaction()` covers a single connection. When a business
operation spans several shards, `ShardManager::transaction()` opens and
coordinates a transaction on every shard involved.

## Usage

```php
use Laravel\RedisShard\Facades\ShardManager;

ShardManager::transaction(['shard1', 'shard2'], function (): void {
    DB::connection('shard1')->table('orders')->insert([...]);
    DB::connection('shard2')->table('orders')->insert([...]);
});
```

Guarantees:

- **All-or-nothing begin** — transactions are opened on every shard before
  the callback runs. If any shard cannot begin, the shards already begun are
  rolled back and a `ShardingException` (chained to the cause) is thrown.
- **All-or-nothing callback** — if the callback throws, every shard rolls
  back and the original exception propagates.
- **Best-effort commit** — shards commit in the order they were begun. If a
  commit fails midway, the shards committed before it stay committed and the
  not-yet-committed shards are rolled back; a `ShardingException` names the
  failing shard.

## What this is *not*

This is **not** a distributed two-phase commit. There is no window in which
a mid-commit crash is recoverable on retry — if the process dies between the
first and the last commit, earlier shards stay committed. Design writes so
that this state is reconcilable:

- prefer ordering commits so the "source of truth" shard commits last, or
- write a reconciliation record (e.g. an `outbox` row) inside the same
  transaction and resolve it asynchronously, or
- make the operation idempotent so replaying it is safe.

For moving existing rows between shards, prefer [`shard:rebalance`](REBALANCE.md),
which is idempotent and write-fenced.

## Replica note

Transactions always open on the shard connections you name — read replicas
are never used for transactional writes, even when
`read_replicas.connections` maps the shard to a replica.
