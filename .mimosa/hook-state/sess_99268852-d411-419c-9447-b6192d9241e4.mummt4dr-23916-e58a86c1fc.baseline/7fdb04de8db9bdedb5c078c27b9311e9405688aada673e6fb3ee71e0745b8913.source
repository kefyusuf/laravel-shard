<?php

declare(strict_types=1);

/**
 * Consumer smoke: boot a Laravel app with kefyusuf/laravel-shard installed
 * and verify core routing works without Redis.
 *
 * Usage (from a Laravel app root):
 *   php /path/to/laravel-shard/examples/smoke.php
 *   php /path/to/laravel-shard/examples/smoke.php /path/to/laravel-app
 */

$appRoot = $argv[1] ?? getcwd();
if (!is_string($appRoot) || !is_dir($appRoot)) {
    fwrite(STDERR, "Laravel app root not found\n");
    exit(1);
}

$autoload = $appRoot . '/vendor/autoload.php';
$bootstrap = $appRoot . '/bootstrap/app.php';

if (!is_file($autoload) || !is_file($bootstrap)) {
    fwrite(STDERR, "Not a Laravel app root: {$appRoot}\n");
    exit(1);
}

require $autoload;

$app = require $bootstrap;
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Disable Redis module so the smoke proves core-only routing.
config(['redis_sharding.modules.redis' => false]);
config(['redis_sharding.modules.queue' => true]);
config(['redis_sharding.connections' => [
    'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    'shard2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
]]);

app()->forgetInstance(Laravel\RedisShard\Contracts\ShardLocatorInterface::class);
app()->forgetInstance(Laravel\RedisShard\Support\ModuleRegistry::class);
app()->forgetInstance('shard.manager');
app()->forgetInstance('shard.locator');

$locator = app(Laravel\RedisShard\Contracts\ShardLocatorInterface::class);
if (!$locator instanceof Laravel\RedisShard\Locators\ArrayShardLocator) {
    fwrite(STDERR, 'expected ArrayShardLocator without redis module, got ' . $locator::class . "\n");
    exit(1);
}

$manager = app('shard.manager');
$connection = $manager->getShardConnection('users', 'user-1');

if (!in_array($connection, ['shard1', 'shard2'], true)) {
    fwrite(STDERR, "unexpected shard connection: {$connection}\n");
    exit(1);
}

echo "smoke ok shard={$connection} locator=" . $locator::class . "\n";
exit(0);
