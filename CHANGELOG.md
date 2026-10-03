# Changelog

All notable changes to `laravel-shard` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [5.0.1] - 2026-10-03

### Removed

- The consumer-repo release dispatch workflow (`.github/workflows/dispatch-consumer-post-release.yml`) and its documentation — the consumer integration repo is no longer available; post-release verification is manual (see `docs/RELEASE_RUNBOOK.md`).

### Fixed

- **Rebalance rejects non-unique source keys**: more than one row matching the configured move key now causes an exception before copying or deleting data, preventing a partial copy followed by a multi-row delete. An actual database unique constraint is still required to prevent concurrent duplicate inserts.
- **Rebalance source deletion closes the re-read/delete window**: with write fencing enabled, a single conditional delete compares the copied source columns. SQLite, MySQL/MariaDB, and PostgreSQL string comparisons are bytewise, preventing case-insensitive collations from hiding a concurrent change; numeric and null values use SQL predicates. Unsupported driver string snapshots are rejected before copying when source deletion and fencing are enabled. A changed source is retained and reported as fenced. SQLite uses this conditional delete without `SELECT ... FOR UPDATE`; cross-shard copy and mapping updates remain non-atomic, and production database and column-type coverage remains limited.
- **Queued jobs no longer overwrite a moved key's mapping**: keyed context restoration uses the current locator mapping when available instead of writing the captured connection back to the locator. Missing mappings and contexts without a key retain the captured connection for compatibility; this fallback cannot detect migration. Only the selected connection must remain configured.
- **Pulse usage counter now flushes in every execution context**: the `shard_request` counter was only flushed on HTTP `RequestHandled`, so queue workers and CLI runs accumulated unbounded state under Octane. The recorder now also flushes between queue job loops (`Looping`), after console commands (`CommandFinished`), on worker shutdown (`WorkerStopping`) and on Octane `OperationTerminated`.

### Changed

- README and rebalance guidance now describe unique-key requirements, conditional deletion, retry risks, and known production limitations instead of promising that concurrent writes can never be lost.
- **Static config reads migrated to `Support\RedisShardConfig`**: the recurring runtime keys (redis connection, monitored tables, shard names/connections, default connection, rebalance settings, registry path, metadata table) now have named accessors and ~20 call sites across commands, monitor, traits, queue, mover, strategy, registry and the metadata model read through them. The remaining static reads (provider bootstrap, the pure validator, the redis key scanner's framework prefix, the module registry factory) are legitimately static.

## [5.0.0] - 2026-10-01

### Changed

- **ShardManager collaborators**: cross-shard transaction coordination extracted into `Database\CrossShardTransactionCoordinator` and shard provisioning into `Support\ShardProvisioner`; `ShardManager::transaction()` and `ShardManager::createShard()` keep their signatures and delegate. ShardManager is back to a focused routing facade (~230 LoC).
- **Request shard context module**: the `shard_connection` request attribute is now owned by `Support\RequestShardContext` (get/set with normalization). The middleware, tenancy bridge, queue serialize/restore and the Shardable trait all go through it instead of touching the raw attribute string.
- **Console presenter seam**: commands build their report data once and render table-or-JSON from the same shape via the new `Console\Concerns\PresentsReports` trait. `shard:status` is converted, removing its four private payload builders and the duplicate metadata query between table and JSON output; remaining commands migrate to the same pattern.
- **ShardableBuilder deepened** (internal, no behavior change): routing-plan inference extracted into a pure `Database\ShardRoutingPlan` module (unit-testable without a database) and pinned-connection mechanics into `Database\ShardExecutor`; the builder's overrides now delegate to them.

### Deprecated

- `Laravel\RedisShard\ShardLocator` (the root-namespace Redis locator) is now an alias subclass of `Laravel\RedisShard\Locators\RedisShardLocator`, which holds the real implementation. Removed in 5.0.

### Removed (BC break)

- `Laravel\RedisShard\Database\ConnectionPool` (deprecated in 4.2.0, never wired) and `Laravel\RedisShard\Modules\RedisModule` (deprecated in 4.2.0, never instantiated) are deleted; both had zero callers.
- `Laravel\RedisShard\Modules\QueueModule::register()` (deprecated in 4.2.0, never invoked) is removed; the module is boot-only.
- `Laravel\RedisShard\Locators\NullShardLocator` is deleted; it was referenced by nothing in src or docs.

### Added

- **Virtual bucket sharding**: new `virtual_bucket` strategy — a key hashes into a fixed bucket (`crc32 % N`, default 1024) and a persistent bucket→shard map (in the shard registry) decides placement. Adding/removing shards never moves buckets implicitly; `shard:bucket` assigns a bucket, `shard:bucket-status` shows the distribution, and `shard:rebalance` moves only the keys of moved buckets. Legacy registry files keep working.
- **Cross-shard transactions**: `ShardManager::transaction(array $shards, Closure $callback)` opens a transaction on every shard, rolls all back when the callback throws, and commits in begin order with best-effort semantics (documented; not a distributed 2PC). See `docs/TRANSACTIONS.md`.

### Fixed

- **Replica reads no longer leak the replica connection onto hydrated models**: models returned from replica-backed reads (find, findMany, first, get, paginate, chunk, cursor, grouped reads) are re-pointed at the shard connection, so a later `save()` writes to the shard — never to the replica.
- **`shard:bucket` assignments are now atomic**: the read-modify-write runs under the registry's exclusive lock via the new `ShardRegistry::mutateBucketMap()`, so concurrent bucket assignments cannot clobber each other.
- **Rebalance fence/delete keying** uses the row's actual key-column value consistently between the copy, the fence re-read and the source delete.

## [4.2.0] - 2026-09-29

### Added

- **Read replica routing**: map shard connections to read replicas in `redis_sharding.read_replicas.connections`; read operations of the shard-aware builder (find, findMany, first, get, count, exists, pluck, paginate, chunk, cursor) target the replica while writes always stay on the shard connection. Unmapped shards read from the shard itself.
- **Rebalance write fencing**: `DatabaseRebalanceDataMover` re-reads the source row between copy and delete; if it changed during the copy window, the delete is skipped and the latest row is propagated to the target instead — a concurrent write can never be lost by a rebalance. Fence trips are reported as `fenced_moves` in the `shard:rebalance` JSON summary. Disable with `REDIS_SHARD_REBALANCE_FENCE=false`.
- **Multi-tenancy bridge**: when `stancl/tenancy` or `spatie/laravel-multitenancy` is installed, the tenant id is used as the shard key and the resolved shard connection is pinned to the request through the same `shard_connection` channel as the `shard` middleware. Enable with `REDIS_SHARD_TENANCY_DRIVER=stancl|spatie`; the bridge loads without either package and registers nothing until configured.
- **Laravel Pulse integration**: when `laravel/pulse` is installed (and Pulse enabled), the package records the per-request shard routing distribution as `shard_request` entries keyed by shard connection and registers a "Shard Usage" dashboard card. Opt out with `REDIS_SHARD_PULSE=false` (`redis_sharding.pulse.enabled`). The routing hot path stays Pulse-agnostic: resolutions are counted in a `Metrics\Pulse\RequestShardUsage` singleton and flushed once per request.
- **Octane compatibility**: locators now expose `resetState()` via the new `ShardStateResettable` contract. When Laravel Octane is installed, the package listens to `RequestTerminated` and flushes process-local locator state (cached mappings, circuit-breaker status) between requests, preventing stale routing under long-running workers. No listener is registered (and no overhead is paid) when Octane is absent. Custom locators can opt in by implementing the contract.

### Changed

- Configuration validation now runs in **every** environment, including `testing`. Previously the provider skipped `ConfigValidator` entirely under the `testing` environment, so invalid `redis_sharding` config shipped silently to consumer CI runs. The `strict_validation` flag (default `true`) now decides the outcome in all environments: invalid config throws `ConfigurationException` when strict, logs a warning when lenient. Set `redis_sharding.strict_validation = false` to keep the previous lenient behavior in test suites.

### Deprecated

- `Laravel\RedisShard\Database\ConnectionPool` — never wired into the container; Laravel's pooled PDO connections already cover this. Removed in 5.0.
- `Laravel\RedisShard\Modules\RedisModule` — never instantiated; the service provider binds the Redis locator directly. Removed in 5.0.
- `Laravel\RedisShard\Modules\QueueModule::register()` — never invoked; the provider binds `RestoreShardContext` itself. Boot-only in 5.0.
- The `redis_sharding.auto_provisioning` config keys (`enabled`, `max_shards`, `threshold`) are marked **reserved and inert**: no auto-provisioner is wired yet. They are kept for forward compatibility and still validated by `ConfigValidator`.

### Fixed

- `shard:rebalance --dry-run --format=json --limit=N` and `shard:report --format=json` now emit valid JSON on Laravel 10 when run through `Artisan::call()`: RefreshDatabase's `artisan()` helper leaves a mocked `OutputStyle` bound in the container on Laravel 10, which swallowed command output. Affected tests now use `withoutMockingConsoleOutput()`.
- `ShardStatusCommand` no longer relies on `Collection::get()` non-null inference: per-shard metadata is fetched with `where('name')->first()`, keeping the `unknown`/`0` fallbacks correct when a shard has no metadata row.
- The empty-`uniqueBy` upsert regression test is skipped below Laravel 13, where the framework does not reject an empty `uniqueBy`.

## [4.1.0] - 2026-09-28

### 4.1.0 Added

- `ShardDiagnosticReport` â€” comprehensive diagnostics (shards, latency, distribution balance, locator, modules, issues).
- `php artisan shard:report` with table/json output.
- Health endpoint supports `?detail=1` (or `?verbose=1`) for the full diagnostic payload.
- `ShardHealthReport` â€” lightweight health snapshot (works without Redis).
- Optional HTTP health endpoint via `redis_sharding.metrics` (`GET /shard-health` by default).
- Laravel Health integration (`ShardStatusCheck` / `LaravelShardHealthCheck`) when the framework health component is installed.
- `shard:rebalance --limit=N` for gradual rebalancing; JSON summary includes `remaining_moves`.
- Dry-run payload includes `limit` / `remaining_if_limited` for scripting.
- `docs/REBALANCE.md` covering dry-run workflow, idempotent mover semantics, and custom movers.
- Unit tests for idempotent `DatabaseRebalanceDataMover` (copy-then-delete, re-run, interrupted copy).

### 4.1.0 Changed

- `DatabaseRebalanceDataMover::move()` is idempotent: a row already on the target is success; a missing row everywhere is failure.

## [4.0.1] - 2026-09-28

### 4.0.1 Added

- Consumer smoke workflow installing the package from Packagist into a fresh Laravel 13 app (`examples/smoke.php`).

### 4.0.1 Changed

- README Quick Start rewritten against the consumer smoke path: modules config, `getShardKeyName()`, `dispatchSharded()`, and smoke verification command.

### 4.0.1 Fixed

- CI: Composer advisory blocking, Docker `safe.directory`, explicit PHP/Laravel matrix includes, PHPStan unused-trait noise.

## [4.0.0] - 2026-09-28

### 4.0.0 Added

- Optional module system: `core` (always on), `redis` (persistent shard map), `queue` (shard-aware jobs) via `redis_sharding.modules`.
- `ArrayShardLocator` and `NullShardLocator` for Redis-free routing (strategy-only or process-local maps).
- Queue module: `ShardContext`, `SerializesShardContext`, `ShardAwareJob`, `RestoreShardContext` job middleware, `ShardContextDispatcher`, and `dispatchSharded()` / `ShardAwareJob::dispatchSharded()`.
- Docker-focused validation targets: `test-focused`, `test-docker`, and `test-docker-focused`.
- A GitHub Actions Docker Compose validation job that runs the full PHPUnit suite against the package's Redis-backed container environment.
- CI job `test-without-redis-module` proving the suite passes with `REDIS_SHARD_MODULE_REDIS=false`.
- Expanded API, README, and Quick Start guidance for deterministic query routing and locator fallback-store operations.

### 4.0.0 Changed

- **Breaking:** package name is now `kefyusuf/laravel-shard` (was `yusuf.kef/laravel-redis-shard`).
- **Breaking:** `illuminate/redis` and `predis/predis` are suggested (optional) dependencies; core routing works without Redis.
- Locator binding is lazy and switches between `RedisShardLocator` (redis module) and `ArrayShardLocator` (default) based on `redis_sharding.modules`.
- Updated support matrix to Laravel 10.x-13.x and PHP 8.2+.
- Updated CI matrix to test Laravel 10/11/12/13 against PHP 8.2/8.3/8.4.
- Made shard strategy resolution container-aware for dependency-injected custom strategies.
- Changed the default sharding strategy to `consistent_hashing` and aligned integration coverage with that default.

### 4.0.0 Fixed

- `Shardable` now resolves shard connection during `creating` even when default connection exists.
- `ShardableBuilder` now falls back to deterministic strategy routing when Redis lookup misses.
- `ShardManager::createShard()` now registers new shards in both package and runtime DB connection config.
- `ShardMetadata` now respects the configurable `metadata_table` name.
- `shard:analyze --format=json` now emits parseable JSON-only output.
- `shard:cleanup` and `shard:health` now scan `shard:*` keys via SCAN iteration.
- Registry writes are now lock-protected and atomic to prevent race-condition corruption.
- `ShardableBuilder` now covers deterministic helper paths such as `get`, `count`, `exists`, `pluck`, `paginate`, `chunk`, `cursor`, `lazy`, `touch`, `increment`, `decrement`, and `upsert`, and fails fast when shard routing is ambiguous.
- Shard locator mappings no longer expire, and the locator now supports local-cache reuse, circuit breaking, and an optional persistent fallback store for Redis outages.
- Consistent hashing now rebuilds its ring when shard topology changes.
- Rebalance metadata updates now refresh `record_count` and `last_rebalanced_at` even when no physical moves are required.

## [2.0.0] - 2024-01-15

### 2.0.0 Added

- Comprehensive testing infrastructure (51 tests across unit, integration, and feature)
- Enhanced monitoring and observability features
- `shard:status` command for viewing shard distribution metrics
- `shard:health` command with auto-fix capability
- `shard:analyze` command for performance analysis
- Configuration validation with helpful error messages
- Cross-shard query builder with aggregation support
- `CrossShardQueryable` trait for models
- Caching layer (`ShardCache`) for performance optimization
- Connection pooling (`ConnectionPool`) for better resource management
- Real-world usage examples and best practices documentation
- GitHub Actions CI/CD workflows
- PHP-CS-Fixer and PHPStan integration

### 2.0.0 Changed

- Improved shard creation command with better validation
- Enhanced error handling in production vs development environments
- Optimized Redis operations for better performance
- Updated documentation with comprehensive guides

### 2.0.0 Fixed

- Configuration validation during service provider boot
- Redis connection handling in tests
- Edge cases in sharding strategies

## [1.0.0] - 2023-12-01

### 1.0.0 Added

- Initial release
- Three sharding strategies (Modulo, Consistent Hashing, Range-Based)
- `Shardable` trait for automatic shard management
- Redis-based shard location tracking
- Basic Artisan commands (`shard:create`, `shard:rebalance`, `shard:install`)
- Middleware for automatic shard routing
- SQLite, MySQL, and PostgreSQL support
- Comprehensive README with examples

[Unreleased]: https://github.com/kefyusuf/laravel-shard/compare/v5.0.1...HEAD
[5.0.1]: https://github.com/kefyusuf/laravel-shard/compare/v5.0.0...v5.0.1
[5.0.0]: https://github.com/kefyusuf/laravel-shard/compare/v4.2.0...v5.0.0
[4.2.0]: https://github.com/kefyusuf/laravel-shard/compare/v4.1.0...v4.2.0
[4.1.0]: https://github.com/kefyusuf/laravel-shard/compare/v4.0.1...v4.1.0
[4.0.1]: https://github.com/kefyusuf/laravel-shard/compare/v4.0.0...v4.0.1
[4.0.0]: https://github.com/kefyusuf/laravel-shard/compare/v3.0.0...v4.0.0
[3.0.0]: https://github.com/kefyusuf/laravel-shard/compare/v2.0.0...v3.0.0
[2.0.0]: https://github.com/kefyusuf/laravel-shard/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/kefyusuf/laravel-shard/releases/tag/v1.0.0
