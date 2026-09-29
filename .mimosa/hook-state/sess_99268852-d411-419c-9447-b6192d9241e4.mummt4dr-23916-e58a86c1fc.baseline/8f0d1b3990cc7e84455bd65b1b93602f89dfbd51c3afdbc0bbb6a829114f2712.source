<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Strategies;

use Laravel\RedisShard\Strategies\ModuloStrategy;
use Laravel\RedisShard\Tests\TestCase;

class ModuloStrategyTest extends TestCase
{
    private ModuloStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ModuloStrategy();
    }

    public function test_it_returns_correct_strategy_name(): void
    {
        $this->assertEquals('modulo', $this->strategy->getName());
    }

    public function test_it_determines_shard_for_numeric_keys(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        
        // Test consistent assignment
        $shard1 = $this->strategy->determine('users', 1, $shards);
        $shard2 = $this->strategy->determine('users', 1, $shards);
        $this->assertEquals($shard1, $shard2);
        
        // Test different keys get distributed
        $results = [];
        for ($i = 1; $i <= 9; $i++) {
            $results[] = $this->strategy->determine('users', $i, $shards);
        }
        
        // Should use all shards
        $uniqueShards = array_unique($results);
        $this->assertCount(3, $uniqueShards);
    }

    public function test_it_determines_shard_for_string_keys(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        
        $shard1 = $this->strategy->determine('users', 'user@example.com', $shards);
        $shard2 = $this->strategy->determine('users', 'user@example.com', $shards);
        
        $this->assertEquals($shard1, $shard2);
        $this->assertContains($shard1, $shards);
    }

    public function test_it_distributes_keys_evenly(): void
    {
        $shards = ['shard1', 'shard2', 'shard3'];
        $distribution = [];
        
        // Test with 300 keys
        for ($i = 1; $i <= 300; $i++) {
            $shard = $this->strategy->determine('users', $i, $shards);
            $distribution[$shard] = ($distribution[$shard] ?? 0) + 1;
        }
        
        // Each shard should get exactly 100 keys with modulo strategy
        foreach ($shards as $shard) {
            $this->assertEquals(100, $distribution[$shard]);
        }
    }

    public function test_it_throws_exception_for_empty_shards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No available shards');
        
        $this->strategy->determine('users', 1, []);
    }

    public function test_it_handles_different_data_types(): void
    {
        $shards = ['shard1', 'shard2'];
        
        // Test with different data types
        $testCases = [
            123,
            '123',
            'string-key',
            'user@example.com',
            true,
            false,
        ];
        
        foreach ($testCases as $key) {
            $shard = $this->strategy->determine('users', $key, $shards);
            $this->assertContains($shard, $shards);
            
            // Test consistency
            $shard2 = $this->strategy->determine('users', $key, $shards);
            $this->assertEquals($shard, $shard2);
        }
    }

    public function test_it_works_with_single_shard(): void
    {
        $shards = ['shard1'];
        
        $shard = $this->strategy->determine('users', 123, $shards);
        $this->assertEquals('shard1', $shard);
    }
}
