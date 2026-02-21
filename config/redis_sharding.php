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

    // Redis connection to use for sharding metadata
    'redis_connection' => env('REDIS_SHARD_CONNECTION', 'default'),

    // Default sharding strategy
    'default_strategy' => env('REDIS_SHARD_STRATEGY', 'modulo'),

    // Available sharding strategies
    'strategies' => [
        'modulo' => Laravel\RedisShard\Strategies\ModuloStrategy::class,
        'consistent_hashing' => Laravel\RedisShard\Strategies\ConsistentHashingStrategy::class,
        'range_based' => Laravel\RedisShard\Strategies\RangeBasedStrategy::class,
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

    // Auto-provisioning settings
    'auto_provisioning' => [
        'enabled' => env('REDIS_SHARD_AUTO_PROVISION', false),
        'max_shards' => env('REDIS_SHARD_MAX_SHARDS', 10),
        'threshold' => env('REDIS_SHARD_PROVISION_THRESHOLD', 1000000), // Records per shard
    ],

    // Shard metadata table
    'metadata_table' => env('REDIS_SHARD_METADATA_TABLE', 'shard_metadata'),

    // Tables monitored by health/status/monitoring commands
    'monitored_tables' => ['users', 'orders', 'products'],

    // Cache TTL for shard lookups (in seconds)
    'cache_ttl' => env('REDIS_SHARD_CACHE_TTL', 3600),
];
