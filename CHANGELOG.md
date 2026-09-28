# Changelog

All notable changes to `laravel-shard` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Unreleased Added

- Optional module system: `core` (always on), `redis` (persistent shard map), `queue` (shard-aware jobs) via `redis_sharding.modules`.
- `ArrayShardLocator` and `NullShardLocator` for Redis-free routing (strategy-only or process-local maps).
- Queue module: `ShardContext`, `SerializesShardContext`, `ShardAwareJob`, `RestoreShardContext` job middleware, `ShardContextDispatcher`.
- Docker-focused validation targets: `test-focused`, `test-docker`, and `test-docker-focused`.
- A GitHub Actions Docker Compose validation job that runs the full PHPUnit suite against the package's Redis-backed container environment.
- Expanded API, README, and Quick Start guidance for deterministic query routing and locator fallback-store operations.

### Unreleased Changed

- `illuminate/redis` and `predis/predis` are now suggested (optional) dependencies; core routing works without Redis.
- Locator binding is lazy and switches between `RedisShardLocator` (redis module) and `ArrayShardLocator` (default) based on `redis_sharding.modules`.
- Updated support matrix to Laravel 10.x-13.x and PHP 8.2+.
- Updated CI matrix to test Laravel 10/11/12/13 against PHP 8.2/8.3/8.4.
- Made shard strategy resolution container-aware for dependency-injected custom strategies.
- Changed the default sharding strategy to `consistent_hashing` and aligned integration coverage with that default.

### Unreleased Fixed

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

[Unreleased]: https://github.com/kefyusuf/laravel-shard/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/kefyusuf/laravel-shard/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/kefyusuf/laravel-shard/releases/tag/v1.0.0
