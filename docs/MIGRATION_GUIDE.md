# Migration Guide

This guide helps you migrate an existing Laravel application to use Redis-based sharding.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Migration Process](#migration-process)
- [Step-by-Step Guide](#step-by-step-guide)
- [Data Migration](#data-migration)
- [Rollback Strategy](#rollback-strategy)
- [Testing](#testing)
- [Common Issues](#common-issues)

---

## Prerequisites

Before migrating, ensure you have:

- ✅ Laravel 10.x, 11.x, 12.x, or 13.x installed
- ✅ PHP 8.2 or higher
- ✅ Redis server running and accessible
- ✅ Multiple database servers configured (or multiple databases on one server)
- ✅ Full database backup created
- ✅ Staging environment for testing
- ✅ A shard key that is available before insert for every sharded model

---

## Migration Process

### Overview

```
[Current Single DB] → [Staging with Sharding] → [Production with Sharding]
       ↓                       ↓                         ↓
   Backup Data         Test Migration              Full Migration
                      Validate Results             Monitor Performance
```

### Timeline Estimate

- **Small Application** (<100k records): 1-2 days
- **Medium Application** (100k-1M records): 3-5 days
- **Large Application** (>1M records): 1-2 weeks

---

## Step-by-Step Guide

### 1. Install the Package

```bash
composer require yusuf.kef/laravel-redis-shard
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="Laravel\RedisShard\RedisShardServiceProvider" --tag="config"
```

### 3. Configure Shards

Edit `config/redis_sharding.php`:

```php
return [
    'redis_connection' => 'default',
    'default_strategy' => 'consistent_hashing', // Best for migrations
    
    'connections' => [
        'shard1' => [
            'driver' => 'mysql',
            'host' => env('DB_SHARD_1_HOST', '127.0.0.1'),
            'port' => env('DB_SHARD_1_PORT', '3306'),
            'database' => env('DB_SHARD_1_DATABASE', 'shard_1'),
            'username' => env('DB_SHARD_1_USERNAME', 'root'),
            'password' => env('DB_SHARD_1_PASSWORD', ''),
        ],
        'shard2' => [
            'driver' => 'mysql',
            'host' => env('DB_SHARD_2_HOST', '127.0.0.1'),
            'port' => env('DB_SHARD_2_PORT', '3306'),
            'database' => env('DB_SHARD_2_DATABASE', 'shard_2'),
            'username' => env('DB_SHARD_2_USERNAME', 'root'),
            'password' => env('DB_SHARD_2_PASSWORD', ''),
        ],
        // Add more shards as needed
    ],
];
```

### 4. Update Environment Variables

Add to `.env`:

```env
# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Shard 1
DB_SHARD_1_HOST=127.0.0.1
DB_SHARD_1_PORT=3306
DB_SHARD_1_DATABASE=shard_1
DB_SHARD_1_USERNAME=root
DB_SHARD_1_PASSWORD=secret

# Shard 2
DB_SHARD_2_HOST=127.0.0.1
DB_SHARD_2_PORT=3306
DB_SHARD_2_DATABASE=shard_2
DB_SHARD_2_USERNAME=root
DB_SHARD_2_PASSWORD=secret
```

### 5. Create Shard Databases

```bash
# Create shard databases
php artisan shard:create shard1 --host=127.0.0.1 --port=3306 --database=shard_1 --username=root --password=secret
php artisan shard:create shard2 --host=127.0.0.1 --port=3306 --database=shard_2 --username=root --password=secret
```

### 6. Run Migrations on All Shards

```bash
# Run migrations on each shard
php artisan migrate --database=shard1
php artisan migrate --database=shard2
```

### 7. Update Models

Add the `Shardable` trait to models you want to shard:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Traits\Shardable;

class User extends Model
{
    use Shardable;

    // Specify a shard key that exists before insert.
    protected ?string $shardKey = 'email';
    
    protected $fillable = ['name', 'email', 'password'];
}
```

If your model still uses an auto-increment primary key as the shard key, the package now throws before insert instead of silently writing to the default connection.

---

## Data Migration

### Option A: Offline Migration (Recommended for Small Apps)

**Best for:** Applications that can tolerate downtime

```php
<?php

use App\Models\User;
use Laravel\RedisShard\Facades\ShardManager;
use Illuminate\Support\Facades\DB;

// 1. Put application in maintenance mode
Artisan::call('down');

// 2. Migrate data
DB::table('users')->orderBy('id')->chunk(1000, function ($users) {
    foreach ($users as $user) {
        // Determine target shard
        $shard = ShardManager::getShardConnection('users', $user->email);
        
        // Insert into shard
        DB::connection($shard)->table('users')->insert([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'password' => $user->password,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ]);
    }
});

// 3. Verify migration
$originalCount = DB::table('users')->count();
$shardedCount = 0;
foreach (ShardManager::getAvailableShards() as $shard) {
    $shardedCount += DB::connection($shard)->table('users')->count();
}

if ($originalCount === $shardedCount) {
    echo "✅ Migration successful!\n";
} else {
    echo "❌ Migration failed! Original: {$originalCount}, Sharded: {$shardedCount}\n";
    exit(1);
}

// 4. Bring application back up
Artisan::call('up');
```

### Option B: Online Migration (Zero Downtime)

**Best for:** Large applications requiring zero downtime

```php
<?php

// 1. Dual-write phase: Write to both old and new locations
class User extends Model
{
    use Shardable;

    protected static function booted()
    {
        static::created(function ($user) {
            // Write to shard
            $shard = ShardManager::getShardConnection('users', $user->email);
            DB::connection($shard)->table('users')->insert($user->toArray());
        });

        static::updated(function ($user) {
            // Update shard
            $shard = ShardManager::getShardConnection('users', $user->email);
            DB::connection($shard)->table('users')
                ->where('id', $user->id)
                ->update($user->getDirty());
        });
    }
}

// 2. Background migration: Gradually migrate old data
// Run this as a queued job or console command

Artisan::command('migrate:shards {--batch=1000}', function () {
    $batch = $this->option('batch');
    
    DB::table('users')
        ->whereNotExists(function ($query) {
            // Check if already migrated
            $query->select(DB::raw(1))
                ->from('migration_tracker')
                ->whereColumn('users.id', 'migration_tracker.user_id');
        })
        ->orderBy('id')
        ->limit($batch)
        ->chunk(100, function ($users) {
            foreach ($users as $user) {
                $shard = ShardManager::getShardConnection('users', $user->email);
                
                DB::connection($shard)->table('users')->insert([
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]);
                
                // Mark as migrated
                DB::table('migration_tracker')->insert([
                    'user_id' => $user->id,
                    'migrated_at' => now(),
                ]);
            }
        });
    
    $this->info("Migrated {$batch} users");
});

// 3. Read from shards with fallback
class User extends Model
{
    public static function findByEmail($email)
    {
        // Try shard first
        $shard = ShardManager::getShardConnection('users', $email);
        $user = DB::connection($shard)->table('users')
            ->where('email', $email)
            ->first();
        
        // Fallback to old database
        if (!$user) {
            $user = DB::table('users')->where('email', $email)->first();
            
            // Migrate on read (lazy migration)
            if ($user) {
                DB::connection($shard)->table('users')->insert((array)$user);
            }
        }
        
        return $user;
    }
}

// 4. After full migration, remove dual-write logic
```

---

## Rollback Strategy

### Preparation

1. **Keep Original Database**: Don't delete original data until migration is proven stable
2. **Enable Dual-Write**: Write to both locations during transition
3. **Monitor Metrics**: Track error rates, performance, data consistency

### Rolling Back

```php
// 1. Switch reads back to original database
// In AppServiceProvider::boot()
config(['database.default' => 'mysql']); // Original connection

// 2. Stop writing to shards (if dual-write enabled)
// Remove or comment out shard write logic

// 3. Verify data consistency
$originalCount = DB::connection('mysql')->table('users')->count();
$shardedCount = User::crossShard()->count();

// 4. Clean up if needed
// Drop shard databases or clear Redis keys
Artisan::call('redis:flushdb');
```

### Rollback Checklist

- [ ] Application switched to original database
- [ ] All services reading from original database
- [ ] Verified data consistency
- [ ] Monitored for errors
- [ ] Communicated status to team
- [ ] Documented lessons learned

---

## Testing

### Pre-Migration Testing

```bash
# 1. Test shard creation
php artisan shard:create test_shard --database=test_db --host=127.0.0.1

# 2. Test shard health
php artisan shard:health

# 3. Test distribution
php artisan shard:analyze users

# 4. Run benchmark
php benchmarks/ShardingStrategyBenchmark.php

# 5. Run load test
php examples/LoadTestingExample.php
```

### Post-Migration Validation

```php
// Verify record counts
$original = DB::connection('mysql')->table('users')->count();
$sharded = User::crossShard()->count();
assert($original === $sharded, "Count mismatch!");

// Verify sample records
$sampleIds = DB::connection('mysql')->table('users')
    ->inRandomOrder()
    ->limit(100)
    ->pluck('id');

foreach ($sampleIds as $id) {
    $originalUser = DB::connection('mysql')->table('users')->find($id);
    $shardedUser = User::crossShard()->where('id', $id)->first();
    
    assert($originalUser->email === $shardedUser->email, "Data mismatch for user {$id}");
}
```

---

## Common Issues

### Issue: "No available shards found"
**Solution:** Ensure shards are properly configured in `config/redis_sharding.php`

### Issue: "Redis connection failed"
**Solution:** Verify Redis is running: `redis-cli ping`

### Issue: "Uneven shard distribution"
**Solution:** Use consistent hashing strategy and run rebalancing:
```bash
php artisan shard:rebalance users --strategy=consistent_hashing
```

### Issue: "Slow cross-shard queries"
**Solution:** 
- Add caching for frequently accessed data
- Optimize queries to hit single shards when possible
- Consider denormalization for cross-shard relationships

### Issue: "Migration taking too long"
**Solution:**
- Increase batch size
- Run multiple migration workers in parallel
- Use online migration strategy

---

## Best Practices

1. **Start Small**: Shard one table at a time
2. **Choose Good Shard Keys**: High cardinality, evenly distributed
3. **Monitor Continuously**: Use `shard:health` and `shard:status` regularly
4. **Plan for Growth**: Start with more shards than currently needed
5. **Document Everything**: Keep migration logs and decisions documented
6. **Test Thoroughly**: Test in staging before production
7. **Have a Rollback Plan**: Always be prepared to rollback

---

## Support

For assistance:
- 📖 [Full Documentation](../README.md)
- 🐛 [Report Issues](https://github.com/yusuf-kef/laravel-redis-shard/issues)
- 💬 [Discussions](https://github.com/yusuf-kef/laravel-redis-shard/discussions)

Good luck with your migration! 🚀
