<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Tests\TestCase;

class RebalanceShardCommandTest extends TestCase
{
    public function test_it_fails_without_data_mover_unless_metadata_only_is_used(): void
    {
        $this->artisan('shard:rebalance', [
            'table' => 'users',
            '--force' => true,
        ])
            ->expectsOutput('No data mover is registered for rebalance.')
            ->assertExitCode(1);
    }

    public function test_it_rebalances_with_registered_data_mover(): void
    {
        $locator = new class implements ShardLocatorInterface {
            /** @var array<string, array<string, string>> */
            public array $keys = [
                'users' => [
                    '1' => 'shard1',
                ],
            ];

            public function locate(string $table, mixed $key): ?string
            {
                return $this->keys[$table][(string) $key] ?? null;
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                $this->keys[$table][(string) $key] = $shardConnection;
                return true;
            }

            public function forget(string $table, mixed $key): bool
            {
                if (!isset($this->keys[$table][(string) $key])) {
                    return false;
                }

                unset($this->keys[$table][(string) $key]);
                return true;
            }

            public function getKeysForShard(string $table, string $shardConnection): array
            {
                $keys = [];
                foreach (($this->keys[$table] ?? []) as $key => $shard) {
                    if ($shard === $shardConnection) {
                        $keys[] = $key;
                    }
                }

                return $keys;
            }
        };

        $mover = new class implements RebalanceDataMoverInterface {
            public int $moves = 0;

            public function move(string $table, mixed $key, string $fromShard, string $toShard): bool
            {
                $this->moves++;
                return true;
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);
        $this->app->instance(RebalanceDataMoverInterface::class, $mover);
        Facade::clearResolvedInstance('shard.manager');

        $this->artisan('shard:rebalance', [
            'table' => 'users',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, $mover->moves);
        $this->assertSame('shard2', $locator->locate('users', '1'));
    }
}
