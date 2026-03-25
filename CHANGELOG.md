# Changelog

All notable changes to `laravel-redis-shard` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Updated support matrix to Laravel 10.x-13.x and PHP 8.2+.
- Updated CI matrix to test Laravel 10/11/12/13 against PHP 8.2/8.3/8.4.
- Made shard strategy resolution container-aware for dependency-injected custom strategies.

### Fixed
- `Shardable` now resolves shard connection during `creating` even when default connection exists.
- `ShardableBuilder` now falls back to deterministic strategy routing when Redis lookup misses.
- `ShardManager::createShard()` now registers new shards in both package and runtime DB connection config.
- `ShardMetadata` now respects the configurable `metadata_table` name.
- `shard:analyze --format=json` now emits parseable JSON-only output.
- `shard:cleanup` and `shard:health` now scan `shard:*` keys via SCAN iteration.
- Registry writes are now lock-protected and atomic to prevent race-condition corruption.

## [2.0.0] - 2024-01-15

### Added
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

### Changed
- Improved shard creation command with better validation
- Enhanced error handling in production vs development environments
- Optimized Redis operations for better performance
- Updated documentation with comprehensive guides

### Fixed
- Configuration validation during service provider boot
- Redis connection handling in tests
- Edge cases in sharding strategies

## [1.0.0] - 2023-12-01

### Added
- Initial release
- Three sharding strategies (Modulo, Consistent Hashing, Range-Based)
- `Shardable` trait for automatic shard management
- Redis-based shard location tracking
- Basic Artisan commands (`shard:create`, `shard:rebalance`, `shard:install`)
- Middleware for automatic shard routing
- SQLite, MySQL, and PostgreSQL support
- Comprehensive README with examples

[Unreleased]: https://github.com/yusuf-kef/laravel-redis-shard/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/yusuf-kef/laravel-redis-shard/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/yusuf-kef/laravel-redis-shard/releases/tag/v1.0.0
