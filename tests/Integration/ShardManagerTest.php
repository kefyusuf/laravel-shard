<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Integration;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Tests\TestCase;

class ShardManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skip integration tests if Redis is not available
        $this->skipIfRedisNotAvailable();

        // Clear Redis before each test
        $this->app->make('redis')->flushall();
    }

    public function test_it_can_get_available_shards(): void
    {
        $shards = ShardManager::getAvailableShards();

        $this->assertIsArray($shards);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertContains('shard3', $shards);
    }

    public function test_it_can_determine_shard_connection(): void
    {
        $shardConnection = ShardManager::getShardConnection('users', 123);

        $this->assertIsString($shardConnection);
        $this->assertContains($shardConnection, ['shard1', 'shard2', 'shard3']);

        // Should return same shard for same key
        $shardConnection2 = ShardManager::getShardConnection('users', 123);
        $this->assertEquals($shardConnection, $shardConnection2);
    }

    public function test_it_registers_key_with_locator(): void
    {
        $locator = $this->app->make(ShardLocatorInterface::class);

        // First call should determine and register
        $shardConnection = ShardManager::getShardConnection('users', 456);

        // Verify it's registered in locator
        $locatedShard = $locator->locate('users', 456);
        $this->assertEquals($shardConnection, $locatedShard);
    }

    public function test_it_can_create_new_shard(): void
    {
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'new_shard_',
        ];

        $result = ShardManager::createShard('new_shard', $config);
        $this->assertTrue($result);

        // Verify shard is in available shards
        $shards = ShardManager::getAvailableShards();
        $this->assertContains('new_shard', $shards);

        // Verify metadata record was created
        $metadata = ShardMetadata::where('name', 'new_shard')->first();
        $this->assertNotNull($metadata);
        $this->assertEquals('new_shard', $metadata->connection);
        $this->assertEquals('active', $metadata->status);
    }

    public function test_it_cannot_create_duplicate_shard(): void
    {
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'shard1_',
        ];

        $result = ShardManager::createShard('shard1', $config);
        $this->assertFalse($result);
    }

    public function test_it_can_get_default_strategy(): void
    {
        $strategy = ShardManager::strategy();

        $this->assertInstanceOf(\Laravel\RedisShard\Contracts\ShardStrategyInterface::class, $strategy);
        $this->assertEquals('consistent_hashing', $strategy->getName());
    }

    public function test_it_can_get_specific_strategy(): void
    {
        $strategy = ShardManager::strategy('consistent_hashing');

        $this->assertInstanceOf(\Laravel\RedisShard\Contracts\ShardStrategyInterface::class, $strategy);
        $this->assertEquals('consistent_hashing', $strategy->getName());
    }

    public function test_it_throws_exception_for_unknown_strategy(): void
    {
        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage("Sharding strategy 'unknown' not found");

        ShardManager::strategy('unknown');
    }

    public function test_it_can_get_all_strategies(): void
    {
        $strategies = ShardManager::strategies();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $strategies);
        $this->assertCount(3, $strategies);
        $this->assertTrue($strategies->has('modulo'));
        $this->assertTrue($strategies->has('consistent_hashing'));
        $this->assertTrue($strategies->has('range_based'));
    }

    public function test_it_throws_exception_when_no_shards_available(): void
    {
        // Override config to have no shards
        config(['redis_sharding.connections' => []]);

        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('No available shards found');

        ShardManager::getShardConnection('users', 123);
    }

    public function test_it_distributes_keys_evenly_with_modulo_strategy(): void
    {
        config(['redis_sharding.default_strategy' => 'modulo']);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');

        $distribution = [];

        // Test with 30 keys
        for ($i = 1; $i <= 30; $i++) {
            $shard = ShardManager::getShardConnection('users', $i);
            $distribution[$shard] = ($distribution[$shard] ?? 0) + 1;
        }

        // All shards should be used
        $this->assertCount(3, array_keys($distribution));

        // Each shard should get exactly 10 keys with modulo strategy
        foreach ($distribution as $count) {
            $this->assertEquals(10, $count);
        }
    }
}
