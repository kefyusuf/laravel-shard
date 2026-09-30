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

    private function config(): RedisShardConfig
    {
        return new RedisShardConfig(app(Repository::class));
    }
}
