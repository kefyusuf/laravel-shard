<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Tests\TestCase;

class ShardStatusCommandTest extends TestCase
{
    public function test_it_uses_configured_monitored_tables_for_shard_view(): void
    {
        config()->set('redis_sharding.monitored_tables', ['invoices']);

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
                if ($table === 'invoices' && $shardConnection === 'shard1') {
                    return ['42'];
                }

                return [];
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);

        $this->artisan('shard:status', [
            '--shard' => 'shard1',
        ])
            ->expectsOutput('Table Distribution:')
            ->expectsTable(['Table', 'Keys'], [['invoices', 1]])
            ->assertExitCode(0);
    }
}
