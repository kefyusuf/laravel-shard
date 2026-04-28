<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Monitoring;

use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Monitoring\ShardMonitor;
use Laravel\RedisShard\Tests\TestCase;

class ShardMonitorTest extends TestCase
{
    public function test_it_uses_configured_redis_connection_for_health_checks(): void
    {
        config()->set('redis_sharding.redis_connection', 'telemetry');

        $redis = new class {
            public ?string $usedConnection = null;

            public function connection(string $name)
            {
                $this->usedConnection = $name;

                return new class {
                    public function command(string $name, array $parameters = []): string
                    {
                        return 'PONG';
                    }
                };
            }
        };

        $this->app->instance('redis', $redis);

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
                return [];
            }
        };

        $monitor = new class($locator) extends ShardMonitor {
            public function exposedCheckRedisHealth(): string
            {
                return $this->checkRedisHealth();
            }
        };

        $this->assertSame('healthy', $monitor->exposedCheckRedisHealth());
        $this->assertSame('telemetry', $redis->usedConnection);
    }
}
