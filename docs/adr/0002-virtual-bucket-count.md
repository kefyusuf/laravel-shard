# ADR-0002: 1024 fixed virtual buckets, assigned on first touch, moved only by an operator

Status: Accepted
Date: 2026-10-15

## Context

With `virtual_bucket` routing, a key hashes into a bucket that never changes
(`crc32(table:key) % count`), and a separate `bucket → shard` map decides placement. The bucket count
is the one knob that sets the resolution of every future move: too few buckets and relocating one
bucket drags a large slice of the keyspace with it; too many and the map itself becomes the dominant
entry in the shard registry — it is persisted, versioned and read on routing decisions, so it is data
with a cost.

## Decision

Default `redis_sharding.virtual_buckets.count` to **1024** (env `REDIS_SHARD_VBUCKETS`), read through
`RedisShardConfig::virtualBucketCount()` as `max(1, (int) $count)` so a zero or malformed value cannot
produce a divide-by-zero or an empty ring. Buckets are assigned to a shard on first touch and are
**never** reassigned implicitly when shards are added or removed; relocation is an explicit operation:
`php artisan shard:bucket <n> <shard>` followed by `shard:rebalance`.

## Summary (EN)

A key maps to one of a fixed 1024 buckets by hash, and a persisted map says which shard owns each
bucket. Moving one bucket touches about one thousandth of the keys, which is the whole reason this
strategy exists instead of a hash ring. The number 1024 is a granularity-versus-map-size trade, not a
measured optimum, and it is configurable per deployment. Buckets never move on their own: an operator
assigns a bucket to a shard and then runs the rebalancer. A guard keeps a misconfigured count from
becoming a divide-by-zero at routing time.

## Consequences

- Rebalance cost is bounded and predictable: moving 1 of 1024 buckets plans ~1/1024 of the keys, and
  `shard:bucket-status` reports the current spread.
- Cost: the bucket map lives in the shard registry, so 1024 entries is registry weight on every
  deployment using this strategy — and the map's atomicity is unresolved, tracked as
  [R3 in the production roadmap](../PRODUCTION_ROADMAP.md) (`writeBucketMap` on first assignment is
  not atomic).

## Verification

Which tests hold this decision:

- `tests/Unit/Strategies/VirtualBucketStrategyTest.php::test_bucket_assignment_is_deterministic_and_independent_of_shard_set`,
  `::test_first_touch_assigns_and_persists_the_bucket`, `::test_buckets_never_move_when_shards_are_added`,
  `::test_stale_bucket_assignment_falls_back_and_is_repaired`.
- `tests/Unit/Support/ShardRegistryBucketMapTest.php` — the persisted map and its versioning.
- `tests/Feature/Console/VirtualBucketCommandTest.php` and
  `tests/Feature/Console/VirtualBucketRebalanceTest.php` — the operator-facing move path.

These suites pass locally on PHP 8.4 with no Redis: 53 tests across `tests/Unit/Rebalance`,
`tests/Unit/Strategies` and `tests/Feature/Database`, plus `VirtualBucketCommandTest` (4),
`VirtualBucketRebalanceTest` (1), `ShardRegistryBucketMapTest` (6), `RebalanceShardCommandTest` (9) and
`ShardManagerRegistryTest` (5). The default of 1024 is asserted where the config is read, so changing
it is a deliberate test update rather than a silent drift.
