# Laravel Redis Sharding

[![Tests](https://github.com/yusuf-kef/laravel-redis-shard/workflows/Tests/badge.svg)](https://github.com/yusuf-kef/laravel-redis-shard/actions)
[![Code Quality](https://github.com/yusuf-kef/laravel-redis-shard/workflows/Code%20Quality/badge.svg)](https://github.com/yusuf-kef/laravel-redis-shard/actions)
[![Latest Stable Version](https://poser.pugx.org/yusuf.kef/laravel-redis-shard/v/stable)](https://packagist.org/packages/yusuf.kef/laravel-redis-shard)
[![Total Downloads](https://poser.pugx.org/yusuf.kef/laravel-redis-shard/downloads)](https://packagist.org/packages/yusuf.kef/laravel-redis-shard)
[![License](https://poser.pugx.org/yusuf.kef/laravel-redis-shard/license)](https://packagist.org/packages/yusuf.kef/laravel-redis-shard)
[![PHP Version Require](https://poser.pugx.org/yusuf.kef/laravel-redis-shard/require/php)](https://packagist.org/packages/yusuf.kef/laravel-redis-shard)

A Redis-backed shard locator for Laravel applications that provides deterministic shard routing for shard-key-aware Eloquent workflows.

## ✨ Features

- **Deterministic Shard Routing**: Route shard-key-aware reads and writes to the correct shard
- **Redis-Based Lookup**: Persistent key-to-shard mappings stored in Redis
- **Multiple Strategies**: Support for Modulo, Consistent Hashing, and Range-based sharding
- **Request Routing**: Route middleware and shard-aware builders for single-shard operations
- **Model Integration**: Easy integration using the `Shardable` trait
- **Performance Monitoring**: Built-in monitoring and health checks
- **Auto-Provisioning**: Automatic shard creation when thresholds are reached
- **Connection Pooling**: Optimized database connection management
- **Cross-Shard Operations**: Support for cross-shard queries and aggregations
- **Laravel Integration**: Native Laravel service provider with Artisan commands

## 📋 Requirements

- PHP 8.2, 8.3, or 8.4
- Laravel 10.x, 11.x, 12.x, or 13.x
- Redis server
- Multiple database connections configured

## 📚 Documentation

- **[Quick Start Guide](docs/QUICK_START.md)** - Get started in 5 minutes
- **[API Reference](docs/API.md)** - Complete API documentation
- **[Migration Guide](docs/MIGRATION_GUIDE.md)** - Migrate existing applications
- **[Performance Guide](docs/PERFORMANCE.md)** - Optimization and benchmarks
- **[Release Runbook](docs/RELEASE_RUNBOOK.md)** - Package + consumer release checklist
- **[Example Application](examples/ExampleApplication.md)** - Multi-tenant SaaS example
- **[Load Testing](examples/LoadTestingExample.php)** - Performance testing tools

## 🚀 Installation

Install the package via Composer:

```bash
composer require yusuf.kef/laravel-shard
```

The package will automatically register its service provider thanks to Laravel's package auto-discovery.

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Laravel\RedisShard\RedisShardServiceProvider" --tag="config"
```

Run the migrations:

```bash
php artisan migrate
```

## 🐳 Local Development (Docker)

The repository ships with a Docker stack that bundles PHP (with the `redis` extension via PECL), Composer, and a Redis 7 service so you can run the full test suite without installing anything on the host.

```bash
make build              # build the PHP image (defaults to PHP 8.3)
make install            # install composer dependencies inside the container
make test               # run PHPUnit
make phpstan            # run PHPStan static analysis
make cs                 # run php-cs-fixer in dry-run mode
make matrix             # build and test against PHP 8.2, 8.3, 8.4
```

Override the PHP version via `PHP_VERSION` (e.g. `make PHP_VERSION=8.4 test`).

## ⚙️ Configuration

After publishing the config file, configure your shards in `config/redis_sharding.php`:

```php
return [
    'redis_connection' => 'default',
    'default_strategy' => 'consistent_hashing',

    'strategies' => [
        'modulo' => Laravel\RedisShard\Strategies\ModuloStrategy::class,
        'consistent_hashing' => Laravel\RedisShard\Strategies\ConsistentHashingStrategy::class,
        'range_based' => Laravel\RedisShard\Strategies\RangeBasedStrategy::class,
    ],

    'connections' => [
        'shard1' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'shard_1',
            'username' => 'root',
            'password' => 'secret',
        ],
        'shard2' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'shard_2',
            'username' => 'root',
            'password' => 'secret',
        ],
        // Add more shards as needed
    ],
];
```

## Important Constraints

- Shard-aware query routing is deterministic only when the builder can resolve a single shard from the shard key, currently via explicit shard connection binding, `find`, shard-key `=` predicates, and shard-key `whereIn(...)` predicates.
- Queries that cannot be routed safely now fail fast instead of silently falling back to the default connection.
- Authoritative key-to-shard mappings are persisted in Redis and no longer expire automatically.
- Auto-increment primary keys require an explicit shard key value, request shard binding, or explicit connection before insert.
- `modulo` is suitable only for fixed shard topologies. Adding or removing shards with `modulo` requires planned data migration.

## Supported Query Patterns

| Pattern | Behavior |
| --- | --- |
| `Model::find($id)`, `findMany([...])` | Routes by primary key when the shard key is the primary key |
| `where($shardKey, '=', $value)` | Routes to one shard |
| `whereIn($shardKey, [...])` | Routes to one or more shards and merges results when needed |
| `get`, `first`, `count`, `exists`, `value`, `pluck` | Shard-aware when the query is deterministic |
| `paginate`, `simplePaginate`, `chunk` | Shard-aware when the query is deterministic |
| `update`, `delete`, `touch`, `increment`, `decrement` | Shard-aware when the query is deterministic |
| `upsert` | Shard-aware when every row contains a resolvable shard key value |

## Fail-Fast Query Patterns

- Queries without an explicit shard connection or shard-key predicate
- Shard-key predicates using unsupported operators such as `whereBetween(...)`
- Queries that mix shard-key routing with `orWhere(...)`
- `upsert(...)` payloads that omit the shard key or contain unresolvable shard-key values

These paths now throw `ShardingException` instead of silently using the default connection.

## Locator Fallback Store

If you want shard lookups to survive a Redis outage beyond the current PHP process, configure `redis_sharding.locator.fallback_store` with a Laravel cache store that is independent from Redis.

- Recommended: `database` or `file`
- Acceptable: any shared non-Redis cache backend with separate failure characteristics
- Not useful for outage isolation: a cache store backed by the same Redis cluster
- Test-only: `array`, because it is process-local

Example:

```php
'locator' => [
    'local_cache_limit' => 10000,
    'circuit_breaker_seconds' => 5,
    'fallback_store' => env('REDIS_SHARD_LOCATOR_FALLBACK_STORE', 'database'),
],
```

## 🎯 Quick Start

### 1. Create Your First Shard

```bash
php artisan shard:create shard1 --host=127.0.0.1 --port=3306 --database=shard_1 --username=root --password=secret
```

### 2. Add Sharding to Your Models

Use the `Shardable` trait in your Eloquent models:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Traits\Shardable;

class User extends Model
{
    use Shardable;

    // Specify custom shard key (defaults to primary key)
    protected ?string $shardKey = 'email';

    protected $fillable = ['name', 'email', 'password'];
}
```

### 3. Use Shard-Aware Queries

```php
// Create a user - routed using the shard key value
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => bcrypt('password')
]);

// Single-shard lookup by shard key
$user = User::where('email', 'john@example.com')->first();

// Single-shard bulk lookup by shard key
$users = User::whereIn('email', ['john@example.com', 'jane@example.com'])->get();

// Get shard information
$shardConnection = ShardManager::getShardConnection('users', 'john@example.com');
```

### 4. Use Middleware for Automatic Routing

```php
Route::get('/users/{email}', 'UserController@show')
    ->middleware('shard:users,email');

// Group routes with shard middleware
Route::middleware(['shard:users,email'])->group(function () {
    Route::get('/users/{email}/profile', 'UserController@profile');
    Route::get('/users/{email}/orders', 'UserController@orders');
    Route::put('/users/{email}', 'UserController@update');
});
```

## 🔧 Sharding Strategies

The package supports three different sharding strategies:

### 1. Modulo Strategy

Simple modulo-based distribution. Use only when the shard list is effectively static.

```php
'default_strategy' => 'modulo',
```

**Pros**: Simple, predictable distribution
**Cons**: Adding/removing shards remaps most keys and requires explicit data migration

### 2. Consistent Hashing Strategy (Recommended)

Uses consistent hashing algorithm for better distribution when shards are added/removed.

```php
'default_strategy' => 'consistent_hashing',
```

**Pros**: Minimal data movement when scaling, good distribution
**Cons**: Slightly more complex

### 3. Range-Based Strategy

Distributes data based on key ranges.

```php
'default_strategy' => 'range_based',
```

**Pros**: Good for time-series or sequential data
**Cons**: Can create hotspots if data isn't evenly distributed

## 🎛️ Artisan Commands

### Shard Management

```bash
# Create a new shard with validation
php artisan shard:create shard3 --driver=mysql --host=127.0.0.1 --port=3306 --database=shard_3 --username=root --password=secret

# Install package (run migrations and setup)
php artisan redis-shard:install

# Rebalance data across shards
php artisan shard:rebalance users --strategy=consistent_hashing
```

### Monitoring & Analysis

```bash
# Check overall shard status
php artisan shard:status

# Check specific table distribution
php artisan shard:status --table=users

# Check specific shard details
php artisan shard:status --shard=shard1

# Output in JSON format
php artisan shard:status --format=json
```

### Health Monitoring

```bash
# Check shard health
php artisan shard:health

# Check health and auto-fix issues
php artisan shard:health --fix

# Output health report in JSON
php artisan shard:health --format=json
```

### Performance Analysis

```bash
# Analyze overall distribution
php artisan shard:analyze

# Analyze specific table
php artisan shard:analyze users

# Test specific strategy performance
php artisan shard:analyze --strategy=consistent_hashing --sample-size=10000

# Output analysis in JSON
php artisan shard:analyze --format=json
```

### JSON Output Quick Reference

All commands that support output formatting only accept `--format=table` or `--format=json`.

```bash
php artisan redis-shard:install --skip-migrate --format=json
php artisan shard:create shard4 --driver=sqlite --host=localhost --port=1 --database=database/shard4.sqlite --username=ignored --skip-migrate --format=json
php artisan shard:status --format=json
php artisan shard:cleanup --dry-run --format=json
php artisan shard:health --format=json
php artisan shard:analyze --format=json
php artisan shard:rebalance users --dry-run --format=json
```

Common payload conventions:

- `summary.status`: `ok`, `error`, or command-specific lifecycle value (`dry_run`, `completed`, etc.)
- `error`: Present when command fails in JSON mode
- Detail blocks by command:
  - `install`: `summary.published_config`, `summary.ran_migrations`, `summary.skipped_migrations`
  - `create`: `summary.shard`, `summary.driver`, `summary.database`, `summary.created`
  - `status`: `summary` + `shards` or `tables` detail arrays depending on options
  - `cleanup`: `summary` + `report`
  - `health`: `summary` + `issues` (+ `fix` when `--fix` is used)
  - `analyze`: `summary` + `results`/`analysis`
  - `rebalance`: `summary` (+ `moves` in dry-run mode)

## 📊 Monitoring & Performance

### Built-in Monitoring System

The package includes a comprehensive monitoring system that tracks shard health, performance, and distribution:

```php
use Laravel\RedisShard\Monitoring\ShardMonitor;

$monitor = app(ShardMonitor::class);
$metrics = $monitor->collectMetrics();

// Complete metrics structure
$metrics = [
    'timestamp' => '2024-01-15T10:30:00Z',
    'shards' => [
        'shard1' => [
            'status' => 'active',
            'record_count' => 15000,
            'connection_status' => 'healthy',
            'disk_usage' => ['size_mb' => 245.6, 'table_count' => 12],
            'query_performance' => ['avg_query_time_ms' => 2.3, 'status' => 'fast']
        ]
    ],
    'distribution' => [
        'users' => [
            'total_keys' => 45000,
            'balance_score' => 94.2,
            'std_deviation' => 1.8
        ]
    ],
    'performance' => [
        'redis_latency' => 0.8,
        'shard_lookup_time' => 1.2,
        'cache_hit_rate' => 89.5
    ],
    'health' => [
        'overall_health' => 'healthy',
        'healthy_shards' => 3,
        'total_shards' => 3,
        'health_percentage' => 100.0
    ]
];
```

### Advanced Health Monitoring

```php
// Check specific shard connectivity
$isHealthy = $monitor->checkShardConnection('shard1'); // 'healthy', 'slow', 'unhealthy'

// Get detailed performance metrics
$performance = $monitor->measureQueryPerformance('shard1');

// Monitor Redis connectivity
$redisStatus = $monitor->checkRedisHealth(); // 'healthy', 'unhealthy'
```

### Caching & Performance Optimization

```php
use Laravel\RedisShard\Cache\ShardCache;

$cache = app(ShardCache::class);

// Cache shard connections for faster lookups
$cache->putShardConnection('users', 'john@example.com', 'shard1');
$connection = $cache->getShardConnection('users', 'john@example.com');

// Cache metadata for performance
$cache->putShardMetadata('shard1', $metadata);

// Get cache statistics
$stats = $cache->getStats();
```

### Connection Pooling

```php
use Laravel\RedisShard\Database\ConnectionPool;

$pool = app(ConnectionPool::class);

// Get optimized connection from pool
$connection = $pool->getConnection('shard1');

// Get pool statistics
$stats = $pool->getStats();
// Returns: total_connections, active_connections, pool_utilization, etc.

// Test all connections
$results = $pool->testConnections();
```

## 🔄 Advanced Usage

### Cross-Shard Query Builder

The package now includes a powerful cross-shard query builder that allows you to query across all shards seamlessly:

```php
// Use the CrossShardQueryable trait in your models
use Laravel\RedisShard\Traits\Shardable;
use Laravel\RedisShard\Traits\CrossShardQueryable;

class User extends Model
{
    use Shardable, CrossShardQueryable;
}

// Simple cross-shard queries
$activeUsers = User::crossShard()->where('status', 'active')->get();
$recentUsers = User::crossShard()->orderBy('created_at', 'desc')->limit(10)->get();

// Cross-shard aggregations
$totalUsers = User::crossShard()->count();
$averageSalary = User::crossShard()->avg('salary');
$maxAge = User::crossShard()->max('age');

// Complex queries with multiple conditions
$results = User::crossShard()
    ->where('status', 'active')
    ->whereLike('name', '%john%')
    ->orderBy('created_at', 'desc')
    ->limit(50)
    ->get();
```

### Convenient Cross-Shard Methods

```php
// Search across all shards
$users = User::searchAcrossShards('email', 'john@example.com');

// Find first match across shards
$user = User::findAcrossShards('email', 'john@example.com');

// Get aggregated statistics
$stats = User::aggregateAcrossShards('salary');
// Returns: ['count' => 1000, 'sum' => 50000, 'avg' => 50, 'min' => 20, 'max' => 100]

// Search with LIKE pattern
$users = User::searchLikeAcrossShards('name', '%john%');

// Get recent records across shards
$recentUsers = User::recentAcrossShards(20, 'created_at');

// Paginate across shards
$paginated = User::paginateAcrossShards(1, 15);
// Returns: ['data' => Collection, 'total' => 1000, 'per_page' => 15, ...]
```

### Batch Operations Across Shards

```php
// Batch update across all shards
$updatedCount = User::batchUpdateAcrossShards(
    ['status' => 'inactive'],  // conditions
    ['status' => 'archived']   // updates
);

// Batch delete across shards
$deletedCount = User::batchDeleteAcrossShards(['status' => 'spam']);

// Get shard distribution
$distribution = User::getShardDistribution();
// Returns detailed distribution with counts and percentages per shard

// Execute custom operations on all shards
$results = User::executeOnAllShards(function ($model, $shard) {
    return $model->where('created_at', '>', now()->subDays(7))->count();
});
```

### Manual Shard Selection

```php
// Create user on specific shard
$user = new User(['name' => 'John', 'email' => 'john@example.com']);
$user->setConnection('shard2');
$user->save();

// Query specific shard directly
$users = User::on('shard1')->where('status', 'active')->get();

// Get shard information for a model
$user = User::find(1);
$shardInfo = $user->getShardInfo();
// Returns: ['shard_connection' => 'shard1', 'shard_key' => 'email', 'shard_key_value' => 'john@example.com']
```

## 🛡️ Configuration Validation

The package includes comprehensive configuration validation to prevent common setup issues:

```php
use Laravel\RedisShard\Validation\ConfigValidator;

// Automatic validation on service provider boot
// Manual validation
try {
    ConfigValidator::validate(config('redis_sharding'));
} catch (\Laravel\RedisShard\Exceptions\ConfigurationException $e) {
    echo $e->getMessage();
}

// Get configuration recommendations
$recommendations = ConfigValidator::getRecommendations(config('redis_sharding'));
foreach ($recommendations as $recommendation) {
    echo $recommendation['type'] . ': ' . $recommendation['message'];
}
```

### Configuration Features

- **Connection Validation**: Ensures all shard connections are properly configured
- **Strategy Validation**: Validates sharding strategy classes and interfaces
- **Redis Validation**: Checks Redis connection configuration
- **Auto-Provisioning Validation**: Validates auto-scaling settings
- **Helpful Recommendations**: Provides optimization suggestions

## 🧪 Comprehensive Testing

The package includes extensive testing coverage:

### Test Suites

```bash
# Run all tests (51 tests total)
vendor/bin/phpunit

# Run unit tests (21 tests)
vendor/bin/phpunit --testsuite=Unit

# Run integration tests (22 tests)
vendor/bin/phpunit --testsuite=Integration

# Run feature tests (8 tests)
vendor/bin/phpunit --testsuite=Feature

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Test Categories

**Unit Tests:**

- `ModuloStrategyTest` - Tests modulo distribution algorithm
- `ConsistentHashingStrategyTest` - Tests consistent hashing with shard changes
- `RangeBasedStrategyTest` - Tests range-based distribution

**Integration Tests:**

- `ShardManagerTest` - Tests shard management functionality
- `ShardLocatorTest` - Tests Redis-based shard location
- Cross-component interaction testing

**Feature Tests:**

- `CreateShardCommandTest` - Tests shard creation command
- End-to-end workflow testing
- Command validation and error handling

### Testing Your Implementation

```php
// Test shard distribution
$distribution = [];
for ($i = 1; $i <= 1000; $i++) {
    $shard = ShardManager::getShardConnection('users', $i);
    $distribution[$shard] = ($distribution[$shard] ?? 0) + 1;
}

// Test cross-shard queries
$totalUsers = User::crossShard()->count();
$shardCounts = User::getShardDistribution();

// Test health monitoring
$monitor = app(\Laravel\RedisShard\Monitoring\ShardMonitor::class);
$health = $monitor->collectMetrics()['health'];
```

## 🏗️ Real-World Examples

### E-commerce Application

```php
// Product model with sharding by category
class Product extends Model
{
    use Shardable;

    protected ?string $shardKey = 'category_id';
}

// Order model sharded by user
class Order extends Model
{
    use Shardable;

    protected ?string $shardKey = 'user_email';

    public function user()
    {
        // Cross-shard relationship
        return User::findAcrossShards('email', $this->user_email);
    }
}

// Get sales analytics across all shards
$analytics = Order::crossShard()
    ->where('created_at', '>=', now()->subDays(30))
    ->selectRaw('DATE(created_at) as date, SUM(total) as daily_total')
    ->groupBy('date')
    ->get();
```

### Multi-Tenant SaaS Application

```php
// Tenant-based sharding
class TenantUser extends Model
{
    use Shardable;

    protected ?string $shardKey = 'tenant_id';
}

// Route with tenant-aware sharding
Route::middleware(['shard:tenant_users,tenant_id'])->group(function () {
    Route::get('/tenant/{tenant_id}/users', 'TenantController@users');
    Route::post('/tenant/{tenant_id}/users', 'TenantController@createUser');
});

// Cross-tenant analytics (admin only)
$tenantStats = TenantUser::executeOnAllShards(function ($model, $shard) {
    return [
        'shard' => $shard,
        'active_users' => $model->where('status', 'active')->count(),
        'total_users' => $model->count(),
    ];
});
```

### Social Media Platform

```php
// Posts sharded by user
class Post extends Model
{
    use Shardable, CrossShardQueryable;

    protected ?string $shardKey = 'user_id';
}

// Get trending posts across all shards
$trendingPosts = Post::crossShard()
    ->where('created_at', '>=', now()->subHours(24))
    ->where('likes_count', '>', 100)
    ->orderBy('likes_count', 'desc')
    ->limit(50)
    ->get();

// User feed with cross-shard data
$userFeed = Post::crossShard()
    ->whereIn('user_id', $followingUserIds)
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get();
```

## 📋 Best Practices

### 1. Choosing the Right Shard Key

```php
// ✅ Good: High cardinality, evenly distributed
protected ?string $shardKey = 'user_email';
protected ?string $shardKey = 'user_id';

// ❌ Avoid: Low cardinality, uneven distribution
protected ?string $shardKey = 'status'; // Only few values
protected ?string $shardKey = 'country'; // Uneven distribution
```

### 2. Monitoring and Maintenance

```php
// Regular health checks
Schedule::command('shard:health --fix')->hourly();

// Monitor distribution balance
Schedule::command('shard:analyze --format=json')->daily();

// Collect metrics for monitoring systems
$metrics = app(\Laravel\RedisShard\Monitoring\ShardMonitor::class)->collectMetrics();
```

### 3. Handling Relationships

```php
// Keep related data on the same shard
class User extends Model
{
    use Shardable;

    protected ?string $shardKey = 'email';

    public function profile()
    {
        // Profile uses same shard key
        return $this->hasOne(UserProfile::class, 'user_email', 'email');
    }
}

// For cross-shard relationships, use explicit queries
public function orders()
{
    return Order::searchAcrossShards('user_email', $this->email);
}
```

### 4. Performance Optimization

```php
// Use caching for frequently accessed data
$user = Cache::remember("user:{$email}", 3600, function () use ($email) {
    return User::findAcrossShards('email', $email);
});

// Batch operations for efficiency
$results = User::batchUpdateAcrossShards(
    ['status' => 'inactive'],
    ['last_activity' => now()]
);
```

## 🔁 Consumer Release Automation

This package repo includes an automation workflow that dispatches the consumer repo post-release integration check when a GitHub release is published.

- Workflow: `.github/workflows/dispatch-consumer-post-release.yml`
- Trigger: `release.published`
- Target workflow: `post-release-integration.yml` on consumer repo

Required package-repo secret:

- `CONSUMER_WORKFLOW_TOKEN`: GitHub PAT with `repo` + `workflow` scopes for the consumer repository.

Optional workflow_dispatch inputs:

- `package_tag`
- `consumer_repo`
- `consumer_ref`

## 🤝 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Setup

```bash
# Clone the repository
git clone https://github.com/kefyusuf/laravel-redis-shard.git

# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Check code style
vendor/bin/php-cs-fixer fix --dry-run
```

## 🔧 Troubleshooting

### Common Issues

### 1. Configuration Validation Errors

```bash
# Check configuration
php artisan shard:health

# Common fix: Ensure all required fields are present
'connections' => [
    'shard1' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',      // Required
        'port' => '3306',           // Required
        'database' => 'shard_1',    // Required
        'username' => 'root',       // Required
        'password' => 'secret',
    ],
],
```

### 2. Redis Connection Issues

```bash
# Test Redis connectivity
redis-cli ping

# Check Laravel Redis configuration
php artisan tinker
>>> app('redis')->ping()
```

### 3. Shard Distribution Problems

```bash
# Analyze current distribution
php artisan shard:analyze users

# Rebalance if needed
php artisan shard:rebalance users --strategy=consistent_hashing
```

### 4. Performance Issues

```bash
# Check shard performance
php artisan shard:health

# Enable caching
# In config/redis_sharding.php
'cache_ttl' => 3600, // Enable caching
```

### Debug Mode

```php
// Enable debug logging
config(['app.debug' => true]);

// Check shard assignment
$shard = ShardManager::getShardConnection('users', 'test@example.com');
logger()->info('User assigned to shard', ['shard' => $shard]);

// Monitor query performance
DB::listen(function ($query) {
    logger()->info('Query executed', [
        'sql' => $query->sql,
        'time' => $query->time,
        'connection' => $query->connectionName,
    ]);
});
```

### Performance Tuning

```php
// Optimize cache settings
'cache_ttl' => 7200, // Increase cache TTL

// Connection pool settings
'connection_pool' => [
    'max_connections' => 20,
    'connection_timeout' => 300,
],

// Monitor and adjust based on metrics
$metrics = app(\Laravel\RedisShard\Monitoring\ShardMonitor::class)->collectMetrics();
```

## 📚 Additional Resources

- **[Examples Directory](examples/)** - Complete working examples
- **[Test Suite](tests/)** - Comprehensive test coverage
- **[Configuration Reference](config/redis_sharding.php)** - Full configuration options
- **[API Documentation](docs/)** - Detailed API reference

## 🆕 Changelog

### v2.0.0 (Latest)

- ✅ Added comprehensive testing infrastructure (51 tests)
- ✅ Enhanced monitoring and observability features
- ✅ Improved developer experience with new Artisan commands
- ✅ Added configuration validation and error handling
- ✅ Performance optimizations with caching and connection pooling
- ✅ Cross-shard query builder with aggregation support
- ✅ Real-world examples and best practices documentation

### v1.0.0

- ✅ Basic sharding functionality
- ✅ Multiple sharding strategies
- ✅ Redis-based shard location
- ✅ Laravel integration with service provider

## 📝 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## 🙏 Acknowledgments

- Laravel Framework for the excellent foundation
- Redis for fast key-value storage
- The PHP community for continuous inspiration

---

Made for the Laravel community.

For questions, issues, or contributions, please visit our [GitHub repository](https://github.com/your-username/laravel-redis-shard).
