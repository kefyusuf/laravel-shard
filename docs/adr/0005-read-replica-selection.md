# ADR-0005: Read replicas are a static per-shard config map, never a write or transaction connection

Status: Accepted
Date: 2026-10-15

## Context

Once a shard has a replica, every read has to choose a connection, and the wrong rule is a correctness
bug: a read that lands on a lagging replica right after its own write returns stale data as if it were
current. Options include Laravel's native read/write connection splitting, a sticky-after-write policy,
replica health/lag probes, and a plain static map. Only one of them can be implemented without
inventing a lag-detection mechanism this package does not have.

## Decision

`ReadReplicaResolver::resolve()` maps a shard connection name through
`config('redis_sharding.read_replicas.connections')`; if the entry is missing or not a non-empty
string, it returns the shard connection itself. Resolution is unconditional — no lag check, no health
probe, no session state — and the resolver is reached only from read paths (`ShardExecutor`,
`ShardManager`). Transactional writes never take a replica: `ShardManager::transaction()` opens on the
connections the caller names, documented in `docs/TRANSACTIONS.md` ("read replicas are never used for
transactional writes, even when the map points the shard at a replica").

## Summary (EN)

Replica routing here is a configuration lookup, not a policy engine. Each shard can name one replica
connection; reads that go through the executor resolve to it, and anything unresolved falls back to the
shard. Writes and cross-shard transactions always use the shard connection, so a replica can never
receive a write by accident. There is deliberately no lag measurement, which means the operator owns
staleness: enabling replicas is a decision made with replication lag known some other way. The roadmap
tracks what this does not answer as R6 — read-your-writes inside a request, and locked reads that must
stay on the primary.

## Consequences

- One small, fully tested rule instead of a half-built staleness engine; keeping replicas off is a
  config change, and the first pilot is documented as primary-only.
- Cost: the write-then-read window is unprotected. `ReadReplicaResolver` will happily route a read that
  immediately follows its own write to a lagging replica, and a `SELECT … FOR UPDATE` issued through the
  read path is not pinned to the primary by this rule. Both are R6 (`docs/PRODUCTION_ROADMAP.md`), and
  the roadmap's ordering (R5 → R3 → R4 → R1/R2 → R6) keeps them off the production path until answered.

## Verification

Which tests hold this decision (`tests/Feature/Database/ReadReplicaRoutingTest.php`):

- `test_reads_route_to_the_configured_replica_connection`
- `test_writes_still_target_the_shard_connection` — the boundary of this ADR.
- `test_reads_fall_back_to_the_shard_without_replica_configured`
- `test_models_hydrated_from_replica_reads_carry_the_shard_connection`

No test holds read-your-writes or lock-pinning behaviour, because those are not implemented — marking
this ADR `Proposed` on their account would hide what *is* decided here.
