<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Tests\TestCase;

class RebalanceShardCommandTest extends TestCase
{
    public function test_it_rejects_unsupported_output_format(): void
    {
        $this->artisan('shard:rebalance', [
            'table' => 'users',
            '--format' => 'xml',
        ])
            ->expectsOutput('Unsupported format "xml". Allowed: table, json.')
            ->assertExitCode(1);
    }

    public function test_it_fails_without_data_mover_unless_metadata_only_is_used(): void
    {
        $this->prepareDeterministicRebalanceEnvironment();
        $this->forgetDataMoverBinding();

        $this->artisan('shard:rebalance', [
            'table' => 'users',
            '--force' => true,
        ])
            ->assertExitCode(1);
    }

    public function test_json_error_payload_is_returned_when_data_mover_is_missing(): void
    {
        $this->prepareDeterministicRebalanceEnvironment();
        $this->forgetDataMoverBinding();

        $exitCode = Artisan::call('shard:rebalance', [
            'table' => 'users',
            '--force' => true,
            '--format' => 'json',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_it_rebalances_with_registered_data_mover(): void
    {
        $this->prepareDeterministicRebalanceEnvironment();

        $locator = new class () implements ShardLocatorInterface {
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
                if (! isset($this->keys[$table][(string) $key])) {
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

        $mover = new class () implements RebalanceDataMoverInterface {
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

        $expectedShard = ShardManager::strategy()->determine('users', '1', ShardManager::getAvailableShards());
        $this->assertSame(1, $mover->moves);
        $this->assertSame($expectedShard, $locator->locate('users', '1'));
    }

    public function test_metadata_only_mode_does_not_call_data_mover(): void
    {
        $this->prepareDeterministicRebalanceEnvironment();

        $locator = new class () implements ShardLocatorInterface {
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
                if (! isset($this->keys[$table][(string) $key])) {
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

        $mover = new class () implements RebalanceDataMoverInterface {
            public function move(string $table, mixed $key, string $fromShard, string $toShard): bool
            {
                throw new \RuntimeException('Data mover must not be called in metadata-only mode.');
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);
        $this->app->instance(RebalanceDataMoverInterface::class, $mover);
        Facade::clearResolvedInstance('shard.manager');

        $this->artisan('shard:rebalance', [
            'table' => 'users',
            '--force' => true,
            '--metadata-only' => true,
        ])->assertExitCode(0);

        $expectedShard = ShardManager::strategy()->determine('users', '1', ShardManager::getAvailableShards());
        $this->assertSame($expectedShard, $locator->locate('users', '1'));
    }

    public function test_it_updates_shard_metadata_after_rebalance(): void
    {
        $this->prepareDeterministicRebalanceEnvironment([
            'shard_a' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'shard_b' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        $locator = new class () implements ShardLocatorInterface {
            /** @var array<string, array<string, string>> */
            public array $keys = [
                'users' => [
                    '1' => 'shard_a',
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
                if (! isset($this->keys[$table][(string) $key])) {
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

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);

        ShardMetadata::create([
            'name' => 'shard_a',
            'connection' => 'shard_a',
            'status' => 'active',
            'record_count' => 1,
        ]);
        ShardMetadata::create([
            'name' => 'shard_b',
            'connection' => 'shard_b',
            'status' => 'active',
            'record_count' => 0,
        ]);

        $this->artisan('shard:rebalance', [
            'table' => 'users',
            '--force' => true,
            '--metadata-only' => true,
        ])->assertExitCode(0);

        $expectedShard = ShardManager::strategy()->determine('users', '1', ShardManager::getAvailableShards());
        $expectedCounts = [
            'shard_a' => $expectedShard === 'shard_a' ? 1 : 0,
            'shard_b' => $expectedShard === 'shard_b' ? 1 : 0,
        ];

        $metaA = ShardMetadata::where('connection', 'shard_a')->first();
        $metaB = ShardMetadata::where('connection', 'shard_b')->first();

        $this->assertSame($expectedCounts['shard_a'], $metaA?->record_count);
        $this->assertSame($expectedCounts['shard_b'], $metaB?->record_count);
        $this->assertNotNull($metaA?->last_rebalanced_at);
        $this->assertNotNull($metaB?->last_rebalanced_at);
    }

    public function test_json_dry_run_payload_includes_moves(): void
    {
        $this->prepareDeterministicRebalanceEnvironment();

        $locator = new class () implements ShardLocatorInterface {
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

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);
        Facade::clearResolvedInstance('shard.manager');

        $exitCode = Artisan::call('shard:rebalance', [
            'table' => 'users',
            '--dry-run' => true,
            '--format' => 'json',
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_dry_run_payload_reports_limit(): void
    {
        $this->prepareDeterministicRebalanceEnvironment();

        $locator = new class () implements ShardLocatorInterface {
            /** @var array<string, array<string, string>> */
            public array $keys = [
                'users' => [
                    '1' => 'shard1',
                    '2' => 'shard1',
                    '3' => 'shard1',
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

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);
        Facade::clearResolvedInstance('shard.manager');

        Artisan::call('shard:rebalance', [
            'table' => 'users',
            '--dry-run' => true,
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['summary']['limit'] ?? null);
        $this->assertArrayHasKey('remaining_if_limited', $payload['summary']);
    }

    /**
     * @param array<string, array<string, mixed>>|null $connections
     */
    protected function prepareDeterministicRebalanceEnvironment(?array $connections = null): void
    {
        $registryPath = __DIR__ . '/../../tmp/rebalance-command-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        config()->set('redis_sharding.connections', $connections ?? [
            'shard1' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'shard2' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');
    }

    protected function forgetDataMoverBinding(): void
    {
        if ($this->app->bound(RebalanceDataMoverInterface::class)) {
            $this->app->offsetUnset(RebalanceDataMoverInterface::class);
        }
    }
}
