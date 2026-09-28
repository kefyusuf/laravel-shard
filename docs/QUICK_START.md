# Quick Start Guide

Get up and running with Laravel Redis Sharding in 5 minutes!

## 1. Install (1 minute)

```bash
composer require yusuf.kef/laravel-shard
```

The package auto-registers via Laravel's package discovery.

## 2. Publish Config (30 seconds)

```bash
php artisan vendor:publish --provider="Laravel\RedisShard\RedisShardServiceProvider" --tag="config"
```

This creates `config/redis_sharding.php`.

## 3. Configure Shards (2 minutes)

Edit `config/redis_sharding.php`:

```php
return [
    'redis_connection' => 'default',
    'default_strategy' => 'consistent_hashing',
    
    'connections' => [
        'shard1' => [
            'driver' => 'mysql',
            'host' => env('DB_SHARD_1_HOST', '127.0.0.1'),
            'database' => env('DB_SHARD_1_DATABASE', 'shard_1'),
            'username' => env('DB_SHARD_1_USERNAME', 'root'),
            'password' => env('DB_SHARD_1_PASSWORD', ''),
        ],
        'shard2' => [
            'driver' => 'mysql',
            'host' => env('DB_SHARD_2_HOST', '127.0.0.1'),
            'database' => env('DB_SHARD_2_DATABASE', 'shard_2'),
            'username' => env('DB_SHARD_2_USERNAME', 'root'),
            'password' => env('DB_SHARD_2_PASSWORD', ''),
        ],
    ],
];
```

Add to `.env`:

```env
DB_SHARD_1_DATABASE=shard_1
DB_SHARD_2_DATABASE=shard_2
```

## 4. Run Migrations (1 minute)

```bash
# Create shard databases
php artisan shard:create shard1 --database=shard_1
php artisan shard:create shard2 --database=shard_2

# Run migrations on all shards
php artisan migrate --database=shard1
php artisan migrate --database=shard2
```

## 5. Add to Models (30 seconds)

```php
use Laravel\RedisShard\Traits\Shardable;

class User extends Model
{
    use Shardable;
    
    // Optional: specify shard key (defaults to primary key)
    protected ?string $shardKey = 'email';
}
```

## ðŸŽ‰ You're Done

## Before You Ship

- Use a stable shard key that is known before insert. Auto-increment primary keys need an explicit shard binding or separate shard key column.
- Prefer `consistent_hashing` unless your shard count is fixed for the lifetime of the data.
- Keep single-shard queries anchored on the shard key with `=` or `whereIn(...)`, or bind an explicit shard connection through middleware.
- Queries that cannot be routed deterministically now throw instead of silently using the default connection.
- Supported deterministic builder helpers include `get`, `first`, `count`, `exists`, `value`, `pluck`, `paginate`, `simplePaginate`, `chunk`, `update`, `delete`, `touch`, `increment`, `decrement`, and `upsert`.
- If you configure `redis_sharding.locator.fallback_store`, pick a non-Redis backend such as `database` or `file`; otherwise a Redis outage still takes the fallback with it.

Suggested `.env` setting for production:

```env
REDIS_SHARD_LOCATOR_FALLBACK_STORE=database
```

### Test It Out

```php
// Create a user - routed using the shard key
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => bcrypt('password'),
]);

// Find user on a single shard
$found = User::where('email', 'john@example.com')->first();

// See which shard it's on
$info = $user->getShardInfo();
// ['shard_connection' => 'shard1', 'shard_key' => 'email', ...]
```

### Check Status

```bash
php artisan shard:status
```

## Common Patterns

### Single Shard Query

```php
// Fast - deterministic single-shard lookup
$user = User::where('email', 'john@example.com')->first();
```

### Cross-Shard Query

```php
use Laravel\RedisShard\Traits\CrossShardQueryable;

class User extends Model
{
    use Shardable, CrossShardQueryable;
}

// Query across all shards
$activeUsers = User::crossShard()->where('status', 'active')->get();

// Count across shards
$total = User::crossShard()->count();
```

### Route Middleware

```php
// Automatic shard routing in routes
Route::get('/users/{email}', 'UserController@show')
    ->middleware('shard:users,email');
```

## Next Steps

- ðŸ“– Read [full documentation](../README.md)
- ðŸ—ï¸ See [example application](../examples/ExampleApplication.md)
- ðŸ“š Browse [API reference](../docs/API.md)
- ðŸš€ Check [migration guide](../docs/MIGRATION_GUIDE.md)
- âš¡ Run [performance benchmarks](../benchmarks/README.md)

## Need Help?

- ðŸ› [Report an issue](https://github.com/kefyusuf/laravel-shard/issues)
- ðŸ’¬ [Ask a question](https://github.com/kefyusuf/laravel-shard/discussions)
- ðŸ“§ [Email support](mailto:kefyusuf@gmail.com)

**Pro tip:** Run `php artisan shard:health` regularly to monitor your shards!
