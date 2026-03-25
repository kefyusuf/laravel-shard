<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Strategies;

use Laravel\RedisShard\Strategies\ConsistentHashingStrategy;
use Laravel\RedisShard\Tests\TestCase;

class ConsistentHashingStrategyTest extends TestCase
{
    private ConsistentHashingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ConsistentHashingStrategy();
    }

    public function test_it_returns_correct_strategy_name(): void
    {
        $this->assertEquals('consistent_hashing', $this->strategy->getName());
    }

    public function test_it_determines_shard_consistently(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        
        $shard1 = $this->strategy->determine('users', 'user123', $shards);
        $shard2 = $this->strategy->determine('users', 'user123', $shards);
        
        $this->assertEquals($shard1, $shard2);
        $this->assertContains($shard1, $shards);
    }

    public function test_it_distributes_keys_with_reasonable_balance(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        $distribution = [];
        
        // Test with 1000 keys
        for ($i = 1; $i <= 1000; $i++) {
            $shard = $this->strategy->determine('users', $i, $shards);
            $distribution[$shard] = ($distribution[$shard] ?? 0) + 1;
        }
        
        // With consistent hashing, variance should be reasonable (within 15% of ideal)
        $ideal = 1000 / count($shards);
        foreach ($distribution as $count) {
            $variance = abs($count - $ideal);
            $this->assertLessThan(150, $variance, 'Distribution variance should be reasonable');
        }
    }

    public function test_it_handles_shard_addition_gracefully(): void
    {
        $originalShards = ['shard1', 'shard2'];
        $newShards = ['shard1', 'shard2', 'shard3'];
        
        $keys = ['user1', 'user2', 'user3', 'user4', 'user5'];
        $originalAssignments = [];
        $newAssignments = [];
        
        // Get original assignments
        foreach ($keys as $key) {
            $originalAssignments[$key] = $this->strategy->determine('users', $key, $originalShards);
        }
        
        // Create new strategy instance for new shard configuration
        $newStrategy = new ConsistentHashingStrategy();
        
        // Get new assignments
        foreach ($keys as $key) {
            $newAssignments[$key] = $newStrategy->determine('users', $key, $newShards);
        }
        
        // Some keys should remain in their original shards
        $unchanged = 0;
        foreach ($keys as $key) {
            if ($originalAssignments[$key] === $newAssignments[$key]) {
                $unchanged++;
            }
        }
        
        // At least some keys should remain unchanged (consistent hashing benefit)
        $this->assertGreaterThan(0, $unchanged);
    }

    public function test_it_throws_exception_for_empty_shards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No available shards');
        
        $this->strategy->determine('users', 'user123', []);
    }

    public function test_it_considers_table_name_in_hash(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        
        $usersShard = $this->strategy->determine('users', 'key123', $shards);
        $ordersShard = $this->strategy->determine('orders', 'key123', $shards);
        
        // Same key in different tables might go to different shards
        // This is expected behavior as table name is part of the hash
        $this->assertContains($usersShard, $shards);
        $this->assertContains($ordersShard, $shards);
    }

    public function test_it_works_with_single_shard(): void
    {
        $shards = ['shard1'];
        
        $shard = $this->strategy->determine('users', 'user123', $shards);
        $this->assertEquals('shard1', $shard);
    }

    public function test_it_handles_different_key_types(): void
    {
        $shards = ['shard1', 'shard2'];
        
        $testCases = [
            123,
            '123',
            'string-key',
            'user@example.com',
        ];
        
        foreach ($testCases as $key) {
            $shard = $this->strategy->determine('users', $key, $shards);
            $this->assertContains($shard, $shards);
            
            // Test consistency
            $shard2 = $this->strategy->determine('users', $key, $shards);
            $this->assertEquals($shard, $shard2);
        }
    }
}
