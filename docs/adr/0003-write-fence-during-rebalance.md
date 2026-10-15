# ADR-0003: Fenced source deletion — copy, re-read, then a conditional delete that compares every column

Status: Accepted
Date: 2026-10-15

## Context

`shard:rebalance` moves rows between shards by copying to the target and deleting from the source.
Those are two writes on two connections with no transaction between them, so a write that lands on
the source during the copy window is silently destroyed by an unconditional delete. The alternatives
were: lock the source row (`SELECT … FOR UPDATE` — unavailable on SQLite and a throughput problem
where it exists), run a two-phase protocol across shards (out of scope, see
[ADR-0004](0004-cross-shard-transaction-strategy.md)), or make the delete conditional on the source
still being the row that was copied.

## Decision

The bundled `DatabaseRebalanceDataMover` fences the delete:

1. Copy with `updateOrInsert` keyed on a column configured in `rebalance.table_key_columns`.
2. Re-read the source. If it changed during the copy window, skip the delete, propagate the latest
   row to the target, and count the event as a fence trip (`fenced_moves` in `--format=json`).
3. If the re-read still matches, delete with one conditional `DELETE` carrying comparison predicates
   for **every** copied column — strings compared bytewise (`CAST … AS BLOB` on SQLite,
   `CAST … AS BINARY` on MySQL/MariaDB, `convert_to(CAST … AS text, 'UTF8')` on PostgreSQL) so a
   case-insensitive collation cannot hide `Alice` → `ALICE`; numbers by SQL equality; nulls by
   `IS NULL`.
4. Refuse to start when the key column matches more than one source row — the guard runs before any
   copy or delete, and the database's own unique constraint remains the real enforcement.

Fencing is on by default and disabled with `REDIS_SHARD_REBALANCE_FENCE=false`, which the docs mark
not recommended.

## Summary (EN)

A rebalance move copies the row, re-reads the source, and deletes only if every copied column still
matches. String comparison is bytewise at the SQL level so a case-insensitive collation cannot hide a
concurrent write. When the source changed mid-copy, the delete is skipped, the newest row is pushed to
the target, and the trip is reported as `fenced_moves`. A key that matches several source rows aborts
before anything is written. This is deliberately not online resharding: the retained source row still
needs a human to reconcile it, and the roadmap tracks that as R1.

## Consequences

- A concurrent write can no longer be destroyed by the mover — the failure mode becomes "two copies
  exist, operator reconciles", which is recoverable and observable.
- Cost: it is **not** a lossless online-move guarantee. The command may still repoint the mapping to
  the target while a fenced source row remains, and an interrupted run can overwrite the target from
  a stale source. `docs/PRODUCTION_ROADMAP.md` R1 states exactly this and gates live rebalance out of
  the first pilot; binary/blob columns need a custom mover.

## Verification

Which tests hold this decision (`tests/Unit/Rebalance/DatabaseRebalanceDataMoverTest.php`):

- `test_concurrent_write_during_copy_fences_the_delete_and_propagates_the_latest_row`
- `test_write_after_reread_is_not_deleted`
- `test_case_insensitive_collation_does_not_hide_a_write_after_reread`
- `test_non_unique_source_key_is_rejected_before_either_shard_changes`
- `test_fencing_can_be_disabled` — the opt-out is a tested switch, not an assumption.

Supporting: `tests/Feature/Console/RebalanceShardCommandTest.php::test_json_payload_reports_fenced_moves`
(reports the trip), `tests/Unit/Rebalance/IdempotentMoverTest.php::test_move_recovers_from_copy_without_delete`
and `::test_move_is_idempotent_after_completion` (the resume semantics), and
`tests/Feature/Database/CrossShardTransactionTest.php::test_partial_commit_is_reported_without_claiming_atomicity`
(no cross-shard transaction is implied by a move).
