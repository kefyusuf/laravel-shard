# Glossary

Domain terms for laravel-shard. Use these names in code, docs and reviews so
that seams keep stable names across refactors. Architecture vocabulary
(module, interface, depth, seam, adapter, leverage, locality) follows
"deep module" semantics: a lot of behaviour behind a small interface.

## Routing

**Shard** — one physical database connection that holds a horizontal slice of
a table's rows. Named by its connection key (`shard1`, `shard2`, …).

**Shard key** — the column whose value decides which shard a row belongs to
(`getShardKeyName()` on a Shardable model). May differ from the primary key.

**Shard locator** — the module that answers "which shard holds row X of table
Y" and persists the answer. Interface: `Contracts\ShardLocatorInterface`.
Adapters: `Locators\RedisShardLocator` (durable map, LRU local cache,
fallback store, circuit breaker) and `Locators\ArrayShardLocator`
(process-local map).

**Routing plan** — the pure decision produced from a query's where-clauses:
`single` (one shard), `many` (per-shard key groups), `none` (no shard-key
constraint), or `unsupported` (not safely routable). Module:
`Database\ShardRoutingPlan` — no I/O; key resolution is injected.

**Strategy** — the fallback placement rule when the locator has no mapping.
Adapters: `modulo`, `consistent_hashing`, `range_based`, `virtual_bucket`
(`Strategies\*`, `Contracts\ShardStrategyInterface`).

**Virtual bucket** — a fixed hash slot (`crc32(table:key) % N`). A key's
bucket never changes when the shard set changes; the persistent **bucket
map** (in the shard registry) decides where each bucket lives, so rebalancing
moves exactly one bucket's keys instead of rehashing half the keyspace.

## Writes and consistency

**Shard executor** — the module that pins model + query connections to a
shard for one operation and restores them afterwards, including read-replica
selection (`Database\ShardExecutor`).

**Read replica** — a per-shard mirror connection used by reads only
(`read_replicas.connections`). Models hydrated from a replica read are
stamped back to the shard connection, so a later `save()` never writes to
the replica.

**Write fencing** — the rebalance mover's rule: re-read the source row
between copy and delete; if it changed, skip the delete and propagate the
latest row instead. A concurrent write can never be lost by a rebalance.

**Cross-shard transaction** — `ShardManager::transaction()`: all-or-nothing
begin and callback, best-effort ordered commit (not a distributed 2PC).
Module: `Database\CrossShardTransactionCoordinator`.

## Request and queue affinity

**Request shard context** — the request-scoped "which shard am I pinned to"
channel. Module: `Support\RequestShardContext` (owning the
`shard_connection` attribute). Writers: the `shard` middleware, the tenancy
bridge, queue context restoration. Reader: the Shardable trait.

**Shard-aware job** — a queued job carrying a captured **shard context**
(table, key, connection) so a worker restores the same affinity
(`Queue\ShardContext`, `dispatchSharded()`).

## Operations

**Shard registry** — the versioned JSON document persisting shard connection
definitions and the virtual-bucket map (`Support\ShardRegistry`; atomically
written under a file lock).

**Rebalance** — moving keys from one shard to another to restore balance.
Planner: compares each located key against the strategy's decision; mover:
`Rebalance\DatabaseRebalanceDataMover` (idempotent copy-then-delete with
write fencing). Bucket moves via `shard:bucket` + `shard:rebalance`.

**Shard metadata** — the `shard_metadata` table row per shard (status,
record count, rebalance timestamps; `Models\ShardMetadata`).

**Module flags** — the config-driven feature switches `modules.core` (always
on), `modules.redis` (persistent map), `modules.queue` (shard-aware jobs).
The service provider is the integration seam: optional ecosystem bridges
(Octane, Pulse, tenancy packages) are registered only when detected.
