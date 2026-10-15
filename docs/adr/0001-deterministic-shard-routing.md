# ADR-0001: Routing is a pluggable strategy behind one port, with consistent hashing as the default

Status: Accepted
Date: 2026-10-15

## Context

Every shard decision in this package reduces to one function: given a table and a key, which
connection owns it. The four plausible answers disagree about what happens when the shard set
changes. `modulo` is the fastest but remaps nearly every key when a shard is added; range-based
suites sequential data but hot ranges concentrate; a hash ring moves only ~1/N of keys; a versioned
bucket map moves nothing until an operator moves a bucket. Picking one in code would have forced
every consumer to accept a rebalance story it did not choose, and would have made the package's
"controlled resharding" claim impossible to defend.

## Decision

`ShardStrategyInterface::determine(string $table, mixed $key, array $availableShards): string` is the
only routing seam. Four implementations ship: `ModuloStrategy`, `ConsistentHashingStrategy`,
`RangeBasedStrategy`, `VirtualBucketStrategy`. `config('redis_sharding.default_strategy')`
(env `REDIS_SHARD_STRATEGY`) selects the default, which is `consistent_hashing`; a consumer may bind
its own class. Every strategy hashes `table:key`, so placement is table-scoped by construction.

## Summary (EN)

Shard placement is one interface method, not a hard-coded formula. Four strategies implement it:
modulo, consistent hashing, range-based, and virtual buckets. They differ in how much of the keyspace
moves when the shard count changes, so the choice is configuration rather than a fork. The default is
consistent hashing because it spreads keys and displaces roughly one shard's worth of keys when the
topology grows. Callers only ever see the port, so a deployment can switch strategies with an ADR of
its own instead of a patch in this package.

## Consequences

- A wrong strategy choice is a config change, not a migration — but it silently rehashes everything,
  so the switch is an operator decision the docs must gate (`docs/REBALANCE.md`).
- Cost: four implementations to test, and the interface cannot express strategies that need more than
  `(table, key, shards)` — e.g. a lookup table of placements would need a different seam.

## Verification

Which tests hold this decision:

- `tests/Unit/Strategies/ModuloStrategyTest.php`, `ConsistentHashingStrategyTest.php`,
  `RangeBasedStrategyTest.php`, `VirtualBucketStrategyTest.php` — one suite per strategy.
- `tests/Unit/Strategies/VirtualBucketStrategyTest.php::test_bucket_assignment_is_deterministic_and_independent_of_shard_set`
  and `::test_buckets_never_move_when_shards_are_added` — the topology-stability property the
  `virtual_bucket` option exists to provide.
- `tests/Unit/ShardManagerRegistryTest.php` — strategy resolution through the manager.
