# ADR-0004: Cross-shard writes commit best-effort — no two-phase commit, and the gap is documented instead of hidden

Status: Accepted
Date: 2026-10-15

## Context

`DB::transaction()` covers one connection. A business operation that writes to two shards has to
decide what "atomic" means when the commits cannot be atomic. Two-phase commit (or XA) buys a
recoverable window in exchange for a coordinator, prepared-transaction storage, participant locking,
and a failure mode where a coordinator crash leaves locks held until timeout. None of that is in this
package, and the roadmap lists XA/2PC as explicitly out of first-release scope
(`docs/PRODUCTION_ROADMAP.md` §1). There is no saga engine either — no compensation log, no step
journal, no reversal runner.

## Decision

`ShardManager::transaction()` (delegating to `CrossShardTransactionCoordinator::transaction()`) ships
three guarantees and names the missing one:

- **All-or-nothing begin** — every shard begins before the callback runs; if any begin fails, the
  already-begun shards roll back and a `ShardingException` chained to the cause is thrown.
- **All-or-nothing callback** — a throwing callback rolls back every shard and propagates.
- **Best-effort commit** — shards commit in the order they were begun. If a commit fails midway,
  earlier shards stay committed, later shards roll back, and the exception names the failing shard.

The residual hole is stated in `docs/TRANSACTIONS.md` under "What this is *not*": a process that dies
between the first and last commit leaves earlier shards committed, and no retry recovers that state.
Consumers are told to design around it — commit the source-of-truth shard last, write a reconciliation
row inside the same transaction, or make the operation idempotent. This package does not ship the
outbox or reconciliation record that second option needs; that is the consumer's schema.

## Summary (EN)

Cross-shard transactions in this package are coordinated, not atomic. Begins are all-or-nothing, a
throwing callback rolls every shard back, and commits are best-effort in begin order. A crash between
the first and last commit leaves a partially committed state on purpose, because the alternative —
two-phase commit — needs a coordinator and prepared-transaction storage this package does not have. The
README and ADR both say so rather than letting `ShardManager::transaction()` read like distributed
atomicity. Callers are pointed at three mitigations: order the commits, write a reconciliation row, or
make the write idempotent.

## Consequences

- The API is honest: a reviewer can find the exact window that is not protected, and the tests pin the
  partial-commit report rather than a claim of atomicity.
- Cost: no saga or recovery tooling ships, so a consumer with real cross-shard money writes must build
  reconciliation itself; the roadmap's M-series gates ("R1/R2 done before tenant moves are offered")
  keep that requirement visible instead of absorbed into the package.

## Verification

Which tests hold this decision (`tests/Feature/Database/CrossShardTransactionTest.php`):

- `test_transaction_commits_on_every_shard`
- `test_transaction_rolls_back_every_shard_when_the_callback_throws`
- `test_transaction_rolls_back_begun_shards_when_a_later_begin_fails`
- `test_transaction_requires_at_least_one_shard`
- `test_partial_commit_is_reported_without_claiming_atomicity`

No test holds a 2PC or saga behaviour, because none exists — adding one requires superseding this ADR.
