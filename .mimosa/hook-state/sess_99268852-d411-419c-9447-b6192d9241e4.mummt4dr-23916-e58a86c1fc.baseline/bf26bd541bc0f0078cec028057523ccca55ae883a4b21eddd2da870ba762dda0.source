<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\ShardLocator;
use Laravel\RedisShard\Tests\TestCase;

class ShardLocatorResilienceTest extends TestCase
{
    public function test_it_serves_cached_mappings_without_requerying_redis(): void
    {
        $client = new class {
            public int $getCalls = 0;

            public function get(string $key): string
            {
                $this->getCalls++;

                return 'shard1';
            }
        };

        $locator = $this->makeLocatorWithClient($client);

        $this->assertSame('shard1', $locator->locate('users', 1));
        $this->assertSame('shard1', $locator->locate('users', 1));
        $this->assertSame(1, $client->getCalls);
    }

    public function test_it_returns_cached_mapping_when_redis_fails_after_a_successful_lookup(): void
    {
        $healthyClient = new class {
            public function get(string $key): string
            {
                return 'shard2';
            }
        };

        $failingClient = new class {
            public function get(string $key): never
            {
                throw new \RuntimeException('Redis offline');
            }
        };

        $manager = new class($this->app, $healthyClient) extends RedisManager {
            public function __construct($app, private object $client)
            {
                parent::__construct($app, 'predis', []);
            }

            public function swapClient(object $client): void
            {
                $this->client = $client;
            }

            public function connection($name = null): Connection
            {
                return new class($this->client) extends Connection {
                    public function __construct(object $client)
                    {
                        $this->client = $client;
                    }

                    public function createSubscription($channels, \Closure $callback, $method = 'subscribe'): void
                    {
                    }
                };
            }
        };

        $locator = new ShardLocator($manager, $this->app->make(Repository::class));

        $this->assertSame('shard2', $locator->locate('users', 2));

        $manager->swapClient($failingClient);

        $this->assertSame('shard2', $locator->locate('users', 2));
    }

    public function test_it_throws_a_sharding_exception_for_uncached_lookups_when_redis_is_unavailable(): void
    {
        $failingClient = new class {
            public function get(string $key): never
            {
                throw new \RuntimeException('Redis offline');
            }
        };

        $locator = $this->makeLocatorWithClient($failingClient);

        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('Shard locator Redis operation failed while locating mapping for users:404.');

        $locator->locate('users', 404);
    }

    public function test_it_can_resolve_mappings_from_the_configured_fallback_store_when_redis_is_unavailable(): void
    {
        config()->set('redis_sharding.locator.fallback_store', 'array');

        $healthyClient = new class {
            public function get(string $key): ?string
            {
                return null;
            }

            public function set(string $key, string $value): bool
            {
                return true;
            }

            public function persist(string $key): bool
            {
                return true;
            }

            public function sadd(string $key, string $value): int
            {
                return 1;
            }
        };

        $failingClient = new class {
            public function get(string $key): never
            {
                throw new \RuntimeException('Redis offline');
            }
        };

        $healthyLocator = $this->makeLocatorWithClient($healthyClient);
        $healthyLocator->register('users', 55, 'shard1');

        $failingLocator = $this->makeLocatorWithClient($failingClient);

        $this->assertSame('shard1', $failingLocator->locate('users', 55));
        $this->assertSame(['55'], $failingLocator->getKeysForShard('users', 'shard1'));
    }

    private function makeLocatorWithClient(object $client): ShardLocator
    {
        $manager = new class($this->app, $client) extends RedisManager {
            public function __construct($app, private object $client)
            {
                parent::__construct($app, 'predis', []);
            }

            public function connection($name = null): Connection
            {
                return new class($this->client) extends Connection {
                    public function __construct(object $client)
                    {
                        $this->client = $client;
                    }

                    public function createSubscription($channels, \Closure $callback, $method = 'subscribe'): void
                    {
                    }
                };
            }
        };

        return new ShardLocator($manager, $this->app->make(Repository::class), $this->app->make('cache'));
    }
}