<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Tests\TestCase;

class ShardStatusTablePercentagesTest extends TestCase
{
    public function test_table_json_percentages_are_calculated_from_final_total(): void
    {
        $registryPath = __DIR__ . '/../../tmp/status-table-percentages-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');

        $locator = new class implements ShardLocatorInterface {
            public function locate(string $table, mixed $key): ?string
            {
                return null;
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                return true;
            }

            public function forget(string $table, mixed $key): bool
            {
                return true;
            }

            public function getKeysForShard(string $table, string $shardConnection): array
            {
                if ($table !== 'users') {
                    return [];
                }

                if ($shardConnection === 'shard1') {
                    return ['1', '2', '3'];
                }

                if ($shardConnection === 'shard2') {
                    return ['4'];
                }

                return [];
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);

        $this->artisan('shard:status', [
            '--table' => 'users',
        ])
            ->expectsOutput('Table: users')
            ->expectsTable(
                ['Shard', 'Keys', 'Percentage'],
                [
                    ['shard1', 3, '75%'],
                    ['shard2', 1, '25%'],
                ]
            )
            ->expectsOutput('Total Keys: 4')
            ->assertExitCode(0);
    }
}
