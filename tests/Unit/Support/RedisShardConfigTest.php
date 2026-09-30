<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Support;

use Illuminate\Contracts\Config\Repository;
use Laravel\RedisShard\Support\RedisShardConfig;
use Laravel\RedisShard\Tests\TestCase;

class RedisShardConfigTest extends TestCase
{
    public function test_bucket_count_defaults_to_1024(): void
    {
        config()->set('redis_sharding.virtual_buckets', []);

        $this->assertSame(1024, $this->config()->bucketCount());
    }

    public function test_bucket_count_reads_the_configured_value(): void
    {
        config()->set('redis_sharding.virtual_buckets.count', 64);

        $this->assertSame(64, $this->config()->bucketCount());
    }

    public function test_bucket_count_never_drops_below_one(): void
    {
        config()->set('redis_sharding.virtual_buckets.count', 0);

        $this->assertSame(1, $this->config()->bucketCount());
    }

    public function test_tenancy_driver_defaults_to_empty(): void
    {
        $this->assertSame('', $this->config()->tenancyDriver());
    }

    public function test_tenancy_driver_reads_the_configured_value(): void
    {
        config()->set('redis_sharding.tenancy.driver', 'stancl');

        $this->assertSame('stancl', $this->config()->tenancyDriver());
    }

    public function test_tenancy_table_defaults_to_tenants(): void
    {
        $this->assertSame('tenants', $this->config()->tenancyTable());
    }

    public function test_redis_connection_defaults_to_default(): void
    {
        $this->assertSame('default', $this->config()->redisConnection());
    }

    public function test_monitored_tables_default(): void
    {
        config()->set('redis_sharding', []);

        $this->assertSame(['users', 'orders', 'products'], $this->config()->monitoredTables());
    }

    public function test_monitored_tables_with_null_value_yields_empty(): void
    {
        config()->set('redis_sharding', ['monitored_tables' => null]);

        $this->assertSame([], $this->config()->monitoredTables());
    }

    public function test_shard_names_reads_connection_keys(): void
    {
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite'],
            'shard2' => ['driver' => 'sqlite'],
        ]);

        $this->assertSame(['shard1', 'shard2'], $this->config()->shardNames());
    }

    public function test_shard_connections_returns_the_full_map(): void
    {
        config()->set('redis_sharding.connections', ['shard1' => ['driver' => 'sqlite']]);

        $this->assertSame(['shard1' => ['driver' => 'sqlite']], $this->config()->shardConnections());
    }

    public function test_default_connection_reads_database_config(): void
    {
        config()->set('database.default', 'testing');

        $this->assertSame('testing', $this->config()->defaultConnection());
    }

    public function test_rebalance_settings_with_defaults(): void
    {
        config()->set('redis_sharding.rebalance', []);

        $this->assertSame([], $this->config()->rebalanceTableKeyColumns());
        $this->assertTrue($this->config()->rebalanceDeleteSourceAfterCopy());
        $this->assertTrue($this->config()->rebalanceFenceEnabled());
    }

    public function test_registry_path_falls_back_to_storage(): void
    {
        config()->set('redis_sharding.registry_path', null);

        $this->assertNotSame('', $this->config()->registryPath());
    }

    private function config(): RedisShardConfig
    {
        return new RedisShardConfig(app(Repository::class));
    }
}
