<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Tenancy;

use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Tenancy\SpatieTenantListener;
use Laravel\RedisShard\Tenancy\StanclTenancyListener;
use Laravel\RedisShard\Tenancy\TenancyShardBridge;
use Laravel\RedisShard\Tests\TestCase;

class TenancyBridgeTest extends TestCase
{
    public function test_bridge_resolves_shard_for_tenant_and_sets_request_attribute(): void
    {
        $this->prepareTwoShards();

        $bridge = new TenancyShardBridge('tenants');

        $connection = $bridge->makeCurrent('a@example.com');

        $this->assertNotNull($connection);
        $this->assertSame($connection, app('request')->attributes->get('shard_connection'));
    }

    public function test_bridge_returns_null_for_missing_tenant_id(): void
    {
        $this->prepareTwoShards();

        $bridge = new TenancyShardBridge('tenants');

        $this->assertNull($bridge->makeCurrent(null));
        $this->assertNull(app('request')->attributes->get('shard_connection'));
    }

    public function test_stancl_listener_reads_the_tenant_key(): void
    {
        $this->prepareTwoShards();

        $event = new class () {
            public object $tenancy;

            public function __construct()
            {
                $this->tenancy = new class () {
                    public object $tenant;

                    public function __construct()
                    {
                        $this->tenant = new class () {
                            public function getTenantKey(): string
                            {
                                return 'b@example.com';
                            }
                        };
                    }
                };
            }
        };

        (new StanclTenancyListener(app(TenancyShardBridge::class)))->handle($event);

        $this->assertNotNull(app('request')->attributes->get('shard_connection'));
    }

    public function test_spatie_listener_reads_the_tenant_primary_key(): void
    {
        $this->prepareTwoShards();

        $event = new class () {
            public object $tenant;

            public function __construct()
            {
                $this->tenant = new class () {
                    public function getKey(): string
                    {
                        return 'a@example.com';
                    }
                };
            }
        };

        (new SpatieTenantListener(app(TenancyShardBridge::class)))->handle($event);

        $this->assertNotNull(app('request')->attributes->get('shard_connection'));
    }

    public function test_no_listener_is_registered_when_the_driver_is_not_configured(): void
    {
        config()->set('redis_sharding.tenancy.driver', null);

        $listeners = app('events')->getListeners('Stancl\Tenancy\Events\TenancyInitialized');

        $this->assertSame([], $listeners);
    }

    private function prepareTwoShards(): void
    {
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $this->app->forgetInstance('shard.manager');
        ShardManager::clearResolvedInstances();
        $this->app->forgetInstance(TenancyShardBridge::class);
    }
}
