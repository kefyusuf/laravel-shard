<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Strategies;

use Laravel\RedisShard\Strategies\RangeBasedStrategy;
use Laravel\RedisShard\Tests\TestCase;

class RangeBasedStrategyTest extends TestCase
{
    private RangeBasedStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new RangeBasedStrategy(1000); // Smaller range for testing
    }

    public function test_it_returns_correct_strategy_name(): void
    {
        $this->assertEquals('range_based', $this->strategy->getName());
    }

    public function test_it_determines_shard_consistently(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        
        $shard1 = $this->strategy->determine('users', 123, $shards);
        $shard2 = $this->strategy->determine('users', 123, $shards);
        
        $this->assertEquals($shard1, $shard2);
        $this->assertContains($shard1, $shards);
    }

    public function test_it_groups_keys_by_ranges(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        
        // Keys 0-999 should go to same shard
        $shard1 = $this->strategy->determine('users', 100, $shards);
        $shard2 = $this->strategy->determine('users', 500, $shards);
        $shard3 = $this->strategy->determine('users', 999, $shards);
        
        $this->assertEquals($shard1, $shard2);
        $this->assertEquals($shard2, $shard3);
        
        // Keys 1000-1999 should go to different shard
        $shard4 = $this->strategy->determine('users', 1100, $shards);
        $shard5 = $this->strategy->determine('users', 1500, $shards);
        
        $this->assertEquals($shard4, $shard5);
        // Should be different from first range
        $this->assertNotEquals($shard1, $shard4);
    }

    public function test_it_can_set_and_get_range_size(): void
    {
        $this->assertEquals(1000, $this->strategy->getRangeSize());
        
        $this->strategy->setRangeSize(5000);
        $this->assertEquals(5000, $this->strategy->getRangeSize());
    }

    public function test_it_distributes_ranges_across_shards(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        $distribution = [];
        
        // Test multiple ranges
        for ($range = 0; $range < 9; $range++) {
            $key = $range * 1000 + 500; // Middle of each range
            $shard = $this->strategy->determine('users', $key, $shards);
            $distribution[$shard] = ($distribution[$shard] ?? 0) + 1;
        }
        
        // All shards should be used
        $this->assertCount(3, array_keys($distribution));
        
        // Each shard should get 3 ranges (9 ranges / 3 shards)
        foreach ($distribution as $count) {
            $this->assertEquals(3, $count);
        }
    }

    public function test_it_handles_string_keys(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        
        $shard1 = $this->strategy->determine('users', 'user@example.com', $shards);
        $shard2 = $this->strategy->determine('users', 'user@example.com', $shards);
        
        $this->assertEquals($shard1, $shard2);
        $this->assertContains($shard1, $shards);
    }

    public function test_it_throws_exception_for_empty_shards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No available shards');
        
        $this->strategy->determine('users', 123, []);
    }

    public function test_it_works_with_single_shard(): void
    {
        $shards = ['shard1'];
        
        $shard = $this->strategy->determine('users', 123, $shards);
        $this->assertEquals('shard1', $shard);
    }

    public function test_it_handles_negative_hash_values(): void
    {
        $shards = ['shard1', 'shard2'];
        
        // Test with keys that might produce negative hash values
        $testKeys = ['negative-hash-key', 'another-key', ''];
        
        foreach ($testKeys as $key) {
            $shard = $this->strategy->determine('users', $key, $shards);
            $this->assertContains($shard, $shards);
            
            // Test consistency
            $shard2 = $this->strategy->determine('users', $key, $shards);
            $this->assertEquals($shard, $shard2);
        }
    }

    public function test_it_uses_default_range_size(): void
    {
        $defaultStrategy = new RangeBasedStrategy();
        $this->assertEquals(1000000, $defaultStrategy->getRangeSize());
    }
}
