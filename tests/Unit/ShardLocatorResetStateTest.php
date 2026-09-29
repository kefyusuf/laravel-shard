<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Listeners\FlushShardState;
use Laravel\RedisShard\Locators\ArrayShardLocator;
use Laravel\RedisShard\ShardLocator;
use Laravel\RedisShard\Tests\TestCase;

class ShardLocatorResetStateTest extends TestCase
{
    public function test_reset_state_forces_the_next_lookup_to_requery_redis(): void
    {
        $client = new class () {
            public int $getCalls = 0;

            public function get(string $key): string
            {
                $this->getCalls++;

                return 'shard1';
            }
        };

        $locator = $this->makeLocatorWithClient($client);

        $locator->locate('users', 1);
        $locator->locate('users', 1); // served from the local cache
        $this->assertSame(1, $client->getCalls);

        $locator->resetState();

        $locator->locate('users', 1); // local cache was flushed, Redis is queried again
        $this->assertSame(2, $client->getCalls);
    }

    public function test_reset_state_reopens_an_open_circuit(): void
    {
        $failingClient = new class () {
            public function get(string $key): never
            {
                throw new \RuntimeException('Redis offline');
            }
        };

        $locator = $this->makeLocatorWithClient($failingClient);

        try {
            $locator->locate('users', 1);
            self::fail('Expected the first failed lookup to throw.');
        } catch (ShardingException $exception) {
            $this->assertStringContainsString('Redis operation failed', $exception->getMessage());
        }

        try {
            $locator->locate('users', 1);
            self::fail('Expected the second lookup to hit the open circuit.');
        } catch (ShardingException $exception) {
            $this->assertStringContainsString('circuit is open', $exception->getMessage());
        }

        $locator->resetState();

        // The circuit was reset, so the next lookup retries Redis and fails
        // with the underlying failure, not the open-circuit shortcut.
        try {
            $locator->locate('users', 1);
            self::fail('Expected the lookup to fail while Redis is still down.');
        } catch (ShardingException $exception) {
            $this->assertStringContainsString('Redis operation failed', $exception->getMessage());
            $this->assertStringNotContainsString('circuit is open', $exception->getMessage());
        }
    }

    public function test_array_locator_reset_state_clears_all_mappings(): void
    {
        $locator = new ArrayShardLocator();

        $locator->register('users', 1, 'shard1');
        $locator->register('users', 2, 'shard2');

        $locator->resetState();

        $this->assertNull($locator->locate('users', 1));
        $this->assertNull($locator->locate('users', 2));
        $this->assertSame([], $locator->getKeysForShard('users', 'shard1'));
        $this->assertSame([], $locator->getKeysForShard('users', 'shard2'));
    }

    public function test_flush_listener_resets_the_bound_locator(): void
    {
        // The bound locator may persist mappings durably (Redis), so the flush
        // guarantee is about process-local state only. Assert on a resettable
        // in-memory locator instead of the real Redis-backed singleton.
        $locator = new class () extends ArrayShardLocator {
            public int $resets = 0;

            public function resetState(): void
            {
                $this->resets++;
                parent::resetState();
            }
        };

        $locator->register('users', 1, 'shard1');
        $this->app->instance(ShardLocatorInterface::class, $locator);

        (new FlushShardState($this->app))->handle((object) []);

        $this->assertSame(1, $locator->resets);
        $this->assertNull($locator->locate('users', 1));
    }

    public function test_no_octane_listener_is_registered_when_octane_is_absent(): void
    {
        $listeners = $this->app->make('events')->getListeners('Laravel\Octane\Events\RequestTerminated');

        $this->assertSame([], $listeners);
    }

    private function makeLocatorWithClient(object $client): ShardLocator
    {
        $manager = new class ($this->app, $client) extends RedisManager {
            public function __construct($app, private object $client)
            {
                parent::__construct($app, 'predis', []);
            }

            public function connection($name = null): Connection
            {
                return new class ($this->client) extends Connection {
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
