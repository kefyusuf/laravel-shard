<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Redis Sharding Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the Laravel Redis Sharding package.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Optional modules
    |--------------------------------------------------------------------------
    |
    | core   — always on: strategies, ShardManager, Shardable, builders
    | redis  — Redis-backed persistent key→shard map (ShardLocator)
    | queue  — shard-aware queued jobs (context serialize/restore)
    |
    */
    'modules' => [
        'core' => true,
        'redis' => env('REDIS_SHARD_MODULE_REDIS', true),
        'queue' => env('REDIS_SHARD_MODULE_QUEUE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health / metrics endpoint
    |--------------------------------------------------------------------------
    |
    | When enabled, registers GET {metrics.path} returning a JSON health
    | snapshot. Protect it with the listed route middleware in production.
    |
    */
    'metrics' => [
        'enabled' => env('REDIS_SHARD_METRICS', false),
        'path' => env('REDIS_SHARD_METRICS_PATH', '/shard-health'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Pulse integration
    |--------------------------------------------------------------------------
    |
    | When Laravel Pulse is installed, the package records the per-request
    | shard routing distribution (type: shard_request) and registers a
    | "Shard Usage" dashboard card. Disable to opt out.
    |
    */
    'pulse' => [
        'enabled' => env('REDIS_SHARD_PULSE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy bridge
    |--------------------------------------------------------------------------
    |
    | When a supported tenancy package is installed, the tenant id is used as
    | the shard key and the resolved shard connection is pinned to the request
    | (same channel as the `shard` middleware). Drivers: stancl, spatie.
    |
    */
    'tenancy' => [
        'driver' => env('REDIS_SHARD_TENANCY_DRIVER'),
        'table' => env('REDIS_SHARD_TENANCY_TABLE', 'tenants'),
    ],

    // Redis connection to use for sharding metadata (redis module only)
    'redis_connection' => env('REDIS_SHARD_CONNECTION', 'default'),

    // Default sharding strategy
    'default_strategy' => env('REDIS_SHARD_STRATEGY', 'consistent_hashing'),

    // Available sharding strategies
    'strategies' => [
        'modulo' => Laravel\RedisShard\Strategies\ModuloStrategy::class,
        'consistent_hashing' => Laravel\RedisShard\Strategies\ConsistentHashingStrategy::class,
        'range_based' => Laravel\RedisShard\Strategies\RangeBasedStrategy::class,
        'virtual_bucket' => Laravel\RedisShard\Strategies\VirtualBucketStrategy::class,
    ],

    // Virtual bucket sharding: fixed bucket count, bucket-to-shard assignments
    // persisted in the shard registry (see docs/REBALANCE.md).
    'virtual_buckets' => [
        'count' => env('REDIS_SHARD_VBUCKETS', 1024),
    ],

    // Database connections for shards
    'connections' => [
        // Define your shard connections here
        // 'shard_1' => [
        //     'driver' => 'mysql',
        //     'host' => env('DB_SHARD_1_HOST', '127.0.0.1'),
        //     'port' => env('DB_SHARD_1_PORT', '3306'),
        //     'database' => env('DB_SHARD_1_DATABASE', 'shard_1'),
        //     'username' => env('DB_SHARD_1_USERNAME', 'root'),
        //     'password' => env('DB_SHARD_1_PASSWORD', ''),
        //     'charset' => 'utf8mb4',
        //     'collation' => 'utf8mb4_unicode_ci',
        //     'prefix' => '',
        //     'strict' => true,
        //     'engine' => null,
        // ],
    ],

    // Auto-provisioning settings — RESERVED, currently inert: no provisioner
    // is wired yet (see CHANGELOG 4.2.0). Keys are kept for forward compatibility.
    'auto_provisioning' => [
        'enabled' => env('REDIS_SHARD_AUTO_PROVISION', false),
        'max_shards' => env('REDIS_SHARD_MAX_SHARDS', 10),
        'threshold' => env('REDIS_SHARD_PROVISION_THRESHOLD', 1000000), // Records per shard
    ],

    // Shard metadata table
    'metadata_table' => env('REDIS_SHARD_METADATA_TABLE', 'shard_metadata'),

    // Persistent shard registry path for dynamic shard provisioning
    'registry_path' => env('REDIS_SHARD_REGISTRY_PATH', storage_path('app/redis_sharding_registry.json')),

    // When true, invalid sharding configuration throws even in production.
    'strict_validation' => env('REDIS_SHARD_STRICT_VALIDATION', true),

    // Tables monitored by health/status/monitoring commands
    'monitored_tables' => ['users', 'orders', 'products'],

    /*
    |--------------------------------------------------------------------------
    | Read replicas
    |--------------------------------------------------------------------------
    |
    | Map a shard connection to its read replica connection. Reads routed
    | through the shard-aware builder (find, get, count, pluck, paginate,
    | chunk, cursor, exists) then target the replica; writes always stay on
    | the shard connection itself. Leave empty to disable.
    |
    */
    'read_replicas' => [
        'connections' => [
            // 'shard1' => 'shard1_replica',
        ],
    ],

    // Rebalance data mover settings
    'rebalance' => [
        'enable_default_data_mover' => env('REDIS_SHARD_REBALANCE_ENABLE_MOVER', false),
        'delete_source_after_copy' => env('REDIS_SHARD_REBALANCE_DELETE_SOURCE', true),
        // Write fencing: skip the source delete (and propagate the latest row
        // to the target) when the row changed during the copy window.
        'fence_enabled' => env('REDIS_SHARD_REBALANCE_FENCE', true),
        'table_key_columns' => [
            // 'users' => 'id',
        ],
    ],

    // Deprecated: authoritative shard mappings are persisted and no longer expired.
    'cache_ttl' => env('REDIS_SHARD_CACHE_TTL', 3600),

    'locator' => [
        'local_cache_limit' => env('REDIS_SHARD_LOCATOR_LOCAL_CACHE_LIMIT', 10000),
        'circuit_breaker_seconds' => env('REDIS_SHARD_LOCATOR_CIRCUIT_BREAKER_SECONDS', 5),
        'fallback_store' => env('REDIS_SHARD_LOCATOR_FALLBACK_STORE'),
    ],
];
