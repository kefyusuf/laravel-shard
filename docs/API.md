# API Reference

Complete API reference for Laravel Redis Sharding package.

## Table of Contents

- [ShardManager](#shardmanager)
- [ShardLocator](#shardlocator)
- [Shardable Trait](#shardable-trait)
- [CrossShardQueryable Trait](#crossshardqueryable-trait)
- [Sharding Strategies](#sharding-strategies)
- [Artisan Commands](#artisan-commands)
- [Configuration](#configuration)

---

## ShardManager

Main class for managing database shards.

### ShardManager Methods

#### `getShardConnection(string $table, mixed $key): string`

Determines which shard connection to use for a given key.

```php
use Laravel\RedisShard\Facades\ShardManager;

$shard = ShardManager::getShardConnection('users', 123);
// Returns: 'shard1'
```

**Parameters:**

- `$table` (string) - Table name
- `$key` (mixed) - Shard key value

**Returns:** String - Shard connection name

**Throws:** `ShardingException` if no shards available

---

#### `getAvailableShards(): array`

Gets all configured shard connections.

```php
$shards = ShardManager::getAvailableShards();
// Returns: ['shard1', 'shard2', 'shard3']
```

**Returns:** Array of shard connection names

---

#### `createShard(string $name, array $config): bool`

Creates a new shard dynamically.

```php
$created = ShardManager::createShard('shard4', [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'shard_4',
    'username' => 'root',
    'password' => 'secret',
]);
```

**Parameters:**

- `$name` (string) - Shard connection name
- `$config` (array) - Database configuration

**Returns:** Boolean - True if created, false if already exists

---

#### `strategy(?string $name = null): ShardStrategyInterface`

Gets a sharding strategy instance.

```php
// Get default strategy
$strategy = ShardManager::strategy();

// Get specific strategy
$consistentHash = ShardManager::strategy('consistent_hashing');
```

**Parameters:**

- `$name` (string|null) - Strategy name (optional)

**Returns:** `ShardStrategyInterface` instance

**Throws:** `ShardingException` if strategy not found

---

#### `strategies(): Collection`

Gets all registered strategies.

```php
$allStrategies = ShardManager::strategies();
// Returns Collection of strategy instances
```

**Returns:** `Illuminate\Support\Collection`

---

## ShardLocator

Manages Redis-based shard location tracking.

### Resilience Model

- Redis remains the authoritative shard mapping store.
- The locator keeps an in-process cache for hot mappings.
- An optional `fallback_store` can persist mappings in a secondary Laravel cache store.
- During Redis outages the locator reads from local cache first, then the configured fallback store.
- If no cached or fallback mapping exists, the locator throws `ShardingException` instead of silently guessing.

### ShardLocator Methods

#### `register(string $table, mixed $key, string $shard): bool`

Registers a key's shard location in Redis.

```php
use Laravel\RedisShard\Contracts\ShardLocatorInterface;

$locator = app(ShardLocatorInterface::class);
$locator->register('users', 123, 'shard1');
```

**Parameters:**

- `$table` (string) - Table name
- `$key` (mixed) - Record key
- `$shard` (string) - Shard connection name

**Returns:** Boolean - Success status

---

#### `locate(string $table, mixed $key): ?string`

Finds which shard a key is located on.

```php
$shard = $locator->locate('users', 123);
// Returns: 'shard1' or null if not found
```

**Parameters:**

- `$table` (string) - Table name
- `$key` (mixed) - Record key

**Returns:** String|null - Shard name or null

---

#### `forget(string $table, mixed $key): bool`

Removes a key's shard registration.

```php
$forgotten = $locator->forget('users', 123);
```

**Parameters:**

- `$table` (string) - Table name
- `$key` (mixed) - Record key

**Returns:** Boolean - Success status

---

#### `getKeysForShard(string $table, string $shard): array`

Gets all keys registered to a specific shard.

```php
$keys = $locator->getKeysForShard('users', 'shard1');
// Returns: ['123', '456', '789']
```

**Parameters:**

- `$table` (string) - Table name
- `$shard` (string) - Shard connection name

**Returns:** Array of keys

---

## Shardable Trait

Trait for Eloquent models to enable automatic sharding.

### Query Routing Matrix

The package only routes queries implicitly when it can determine the destination shard safely.

| Query shape | Status | Notes |
| --- | --- | --- |
| `find($id)`, `findMany([...])` | Supported | Routes by primary key when the shard key is also the primary key |
| `where($shardKey, '=', $value)` | Supported | Deterministic single-shard routing |
| `whereIn($shardKey, [...])` | Supported | Routes per shard and merges results when needed |
| `get`, `first`, `firstOrFail`, `sole`, `soleValue`, `value`, `valueOrFail` | Supported | Only when the query is deterministic |
| `cursor`, `lazy`, `lazyById`, `chunk`, `chunkById`, `eachById` | Supported | Only when the query is deterministic |
| `paginate`, `simplePaginate`, `cursorPaginate` | Supported | Only when the query is deterministic |
| `update`, `delete`, `touch`, `increment`, `decrement`, `incrementEach`, `decrementEach` | Supported | Only when the query is deterministic |
| `upsert` | Supported | Every row must include a resolvable shard key |
| `orWhere(...)` mixed into shard routing | Fail-fast | Throws `ShardingException` |
| Unsupported shard-key operators such as `whereBetween(...)` | Fail-fast | Throws `ShardingException` |
| Query without shard-key predicate or explicit shard connection | Fail-fast | Throws `ShardingException` |
| Cross-shard aggregation through the normal Eloquent builder | Unsupported | Use `CrossShardQueryable` instead |

### Shardable Usage

```php
use Laravel\RedisShard\Traits\Shardable;

class User extends Model
{
    use Shardable;

    protected ?string $shardKey = 'email';
}
```

### Properties

#### `$shardKey`

Specifies which column to use as the shard key.

```php
protected ?string $shardKey = 'email'; // Use email  column
protected ?string $shardKey = null;    // Use primary key (default)
```

### Shardable Methods

#### `getShardInfo(): array`

Gets shard information for this model instance.

```php
$user = User::find(1);
$info = $user->getShardInfo();
// Returns: [
//     'shard_connection' => 'shard1',
//     'shard_key' => 'email',
//     'shard_key_value' => 'user@example.com'
// ]
```

**Returns:** Array with shard details

---

## CrossShardQueryable Trait

Trait for executing queries across all shards.

### CrossShardQueryable Usage

```php
use Laravel\RedisShard\Traits\Shardable;
use Laravel\RedisShard\Traits\CrossShardQueryable;

class User extends Model
{
    use Shardable, CrossShardQueryable;
}
```

### CrossShardQueryable Methods

#### `crossShard(): CrossShardQueryBuilder`

Initiates a cross-shard query.

```php
$users = User::crossShard()
    ->where('status', 'active')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
```

**Returns:** `CrossShardQueryBuilder` instance

---

#### `searchAcrossShards(string $column, mixed $value): Collection`

Searches for records across all shards.

```php
$users = User::searchAcrossShards('email', 'john@example.com');
```

**Parameters:**

- `$column` (string) - Column name
- `$value` (mixed) - Value to search for

**Returns:** `Collection` of matching records

---

#### `findAcrossShards(string $column, mixed $value): ?Model`

Finds first matching record across shards.

```php
$user = User::findAcrossShards('email', 'john@example.com');
```

**Parameters:**

- `$column` (string) - Column name
- `$value` (mixed) - Value to search for

**Returns:** Model instance or null

---

#### `aggregateAcrossShards(string $column): array`

Performs aggregations across all shards.

```php
$stats = User::aggregateAcrossShards('age');
// Returns: [
//     'count' => 1000,
//     'sum' => 35000,
//     'avg' => 35,
//     'min' => 18,
//     'max' => 65
// ]
```

**Parameters:**

- `$column` (string) - Column to aggregate

**Returns:** Array with statistics

---

#### `batchUpdateAcrossShards(array $conditions, array $updates): int`

Updates records across all shards.

```php
$updated = User::batchUpdateAcrossShards(
    ['status' => 'inactive'],
    ['status' => 'archived']
);
```

**Parameters:**

- `$conditions` (array) - WHERE conditions
- `$updates` (array) - Values to update

**Returns:** Integer - Number of records updated

---

#### `paginateAcrossShards(int $page = 1, int $perPage = 15): array`

Paginates results across shards.

```php
$paginated = User::paginateAcrossShards(1, 20);
// Returns: [
//     'data' => Collection,
//     'total' => 1000,
//     'per_page' => 20,
//     'current_page' => 1,
//     'last_page' => 50
// ]
```

**Parameters:**

- `$page` (int) - Page number
- `$perPage` (int) - Items per page

**Returns:** Array with pagination data

---

## Sharding Strategies

All strategies implement `ShardStrategyInterface`.

### Common Interface

```php
interface ShardStrategyInterface
{
    public function determine(string $table, mixed $key, array $shards): string;
    public function getName(): string;
}
```

### ModuloStrategy

Simple modulo-based distribution.

```php
$strategy = new ModuloStrategy();
$shard = $strategy->determine('users', 123, ['shard1', 'shard2', 'shard3']);
```

**Best for:** Numeric IDs, static shard count

---

### ConsistentHashingStrategy

Consistent hashing for minimal data movement.

```php
$strategy = new ConsistentHashingStrategy();
$shard = $strategy->determine('users', 'user@example.com', $shards);
```

**Best for:** Dynamic scaling, string keys

---

### RangeBasedStrategy

Range-based distribution for sequential data.

```php
$strategy = new RangeBasedStrategy();
$shard = $strategy->determine('logs', '2024-01-15', $shards);
```

**Best for:** Time-series, sequential data

---

## Artisan Commands

### shard:create

Creates a new shard.

```bash
php artisan shard:create shard4 \
    --host=127.0.0.1 \
    --port=3306 \
    --database=shard_4 \
    --username=root \
    --password=secret
```

**Options:**

- `--driver` - Database driver (default: mysql)
- `--host` - Host address
- `--port` - Port number
- `--database` - Database name
- `--username` - Username
- `--password` - Password

---

### shard:status

Shows shard distribution status.

```bash
# All tables
php artisan shard:status

# Specific table
php artisan shard:status --table=users

# Specific shard
php artisan shard:status --shard=shard1

# JSON output
php artisan shard:status --format=json
```

---

### shard:health

Checks shard health.

```bash
# Check health
php artisan shard:health

# Auto-fix issues
php artisan shard:health --fix

# JSON output
php artisan shard:health --format=json
```

---

### shard:analyze

Analyzes shard performance and distribution.

```bash
# Analyze all
php artisan shard:analyze

# Specific table
php artisan shard:analyze users

# Test strategy
php artisan shard:analyze --strategy=consistent_hashing --sample-size=10000
```

---

### shard:rebalance

Rebalances data across shards.

```bash
php artisan shard:rebalance users --strategy=consistent_hashing
```

---

## Configuration

### config/redis_sharding.php

```php
return [
    // Redis connection name
    'redis_connection' => 'default',
    
    // Default sharding strategy
    'default_strategy' => 'consistent_hashing',
    
    // Available strategies
    'strategies' => [
        'modulo' => ModuloStrategy::class,
        'consistent_hashing' => ConsistentHashingStrategy::class,
        'range_based' => RangeBasedStrategy::class,
    ],
    
    // Shard connections
    'connections' => [
        'shard1' => [...],
        'shard2' => [...],
    ],
    
    // Auto-provisioning settings
    'auto_provisioning' => [
        'enabled' => false,
        'max_shards' => 10,
        'threshold' => 1000000,
    ],
    
    // Cache TTL in seconds
    'cache_ttl' => 3600,

    // Locator resilience settings
    'locator' => [
        'local_cache_limit' => 10000,
        'circuit_breaker_seconds' => 5,
        'fallback_store' => env('REDIS_SHARD_LOCATOR_FALLBACK_STORE'),
    ],
    
    // Metadata table name
    'metadata_table' => 'shard_metadata',
];
```

### Fallback Store Guidance

- Use a fallback store only if it is operationally independent from Redis.
- Good choices: `database`, `file`, or a shared non-Redis cache backend with separate failure characteristics.
- Poor choice: another Laravel cache store backed by the same Redis deployment, because it fails together with Redis.
- `array` is useful in tests but not as a production fallback because it is process-local.
- The fallback store is a resilience layer, not a new source of truth. Redis remains authoritative when healthy.

---

## Exception Handling

### ShardingException

Thrown for sharding-related errors.

```php
use Laravel\RedisShard\Exceptions\ShardingException;

try {
    $shard = ShardManager::getShardConnection('users', $id);
} catch (ShardingException $e) {
    // Handle error
    logger()->error('Sharding error: ' . $e->getMessage());
}
```

### ConfigurationException

Thrown for configuration errors.

```php
use Laravel\RedisShard\Exceptions\ConfigurationException;

try {
    ConfigValidator::validate($config);
} catch (ConfigurationException $e) {
    // Handle configuration error
}
```

---

## Events

Currently, the package does not emit events, but you can hook into Eloquent events:

```php
User::created(function ($user) {
    logger()->info('User created on shard: ' . $user->getShardInfo()['shard_connection']);
});
```

---

## Need Help?

- Ã°Å¸â€œâ€“ [README](../README.md)
- Ã°Å¸â€œËœ [Migration Guide](MIGRATION_GUIDE.md)
- Ã°Å¸â€œÅ  [Performance Docs](PERFORMANCE.md)
- Ã°Å¸Ââ€º [Report Issue](https://github.com/kefyusuf/laravel-shard/issues)
