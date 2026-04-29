<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Integration;

use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Tests\TestCase;

class ShardLocatorTest extends TestCase
{
    private ShardLocatorInterface $locator;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip integration tests if Redis is not available
        $this->skipIfRedisNotAvailable();
        
        $this->locator = $this->app->make(ShardLocatorInterface::class);
        
        // Clear Redis before each test
        $this->app->make('redis')->flushall();
    }

    public function test_it_can_register_and_locate_key(): void
    {
        $result = $this->locator->register('users', 123, 'shard1');
        $this->assertTrue($result);
        
        $located = $this->locator->locate('users', 123);
        $this->assertEquals('shard1', $located);
    }

    public function test_it_returns_null_for_unregistered_key(): void
    {
        $located = $this->locator->locate('users', 999);
        $this->assertNull($located);
    }

    public function test_it_can_forget_registered_key(): void
    {
        // Register key
        $this->locator->register('users', 456, 'shard2');
        $this->assertEquals('shard2', $this->locator->locate('users', 456));
        
        // Forget key
        $result = $this->locator->forget('users', 456);
        $this->assertTrue($result);
        
        // Should not be found anymore
        $located = $this->locator->locate('users', 456);
        $this->assertNull($located);
    }

    public function test_it_returns_false_when_forgetting_unregistered_key(): void
    {
        $result = $this->locator->forget('users', 999);
        $this->assertFalse($result);
    }

    public function test_it_can_get_keys_for_shard(): void
    {
        // Register multiple keys to same shard
        $this->locator->register('users', 100, 'shard1');
        $this->locator->register('users', 200, 'shard1');
        $this->locator->register('users', 300, 'shard2');
        
        $shard1Keys = $this->locator->getKeysForShard('users', 'shard1');
        $shard2Keys = $this->locator->getKeysForShard('users', 'shard2');
        
        $this->assertIsArray($shard1Keys);
        $this->assertIsArray($shard2Keys);
        
        $this->assertCount(2, $shard1Keys);
        $this->assertCount(1, $shard2Keys);
        
        $this->assertContains('100', $shard1Keys);
        $this->assertContains('200', $shard1Keys);
        $this->assertContains('300', $shard2Keys);
    }

    public function test_it_handles_string_keys(): void
    {
        $this->locator->register('users', 'user@example.com', 'shard3');
        
        $located = $this->locator->locate('users', 'user@example.com');
        $this->assertEquals('shard3', $located);
        
        $keys = $this->locator->getKeysForShard('users', 'shard3');
        $this->assertContains('user@example.com', $keys);
    }

    public function test_it_handles_different_tables_separately(): void
    {
        $this->locator->register('users', 123, 'shard1');
        $this->locator->register('orders', 123, 'shard2');
        
        $userShard = $this->locator->locate('users', 123);
        $orderShard = $this->locator->locate('orders', 123);
        
        $this->assertEquals('shard1', $userShard);
        $this->assertEquals('shard2', $orderShard);
    }

    public function test_it_maintains_shard_key_mapping(): void
    {
        // Register keys to different shards
        $this->locator->register('users', 100, 'shard1');
        $this->locator->register('users', 200, 'shard1');
        $this->locator->register('users', 300, 'shard2');
        
        // Forget one key from shard1
        $this->locator->forget('users', 100);
        
        // shard1 should still have key 200
        $shard1Keys = $this->locator->getKeysForShard('users', 'shard1');
        $this->assertCount(1, $shard1Keys);
        $this->assertContains('200', $shard1Keys);
        $this->assertNotContains('100', $shard1Keys);
        
        // shard2 should be unchanged
        $shard2Keys = $this->locator->getKeysForShard('users', 'shard2');
        $this->assertCount(1, $shard2Keys);
        $this->assertContains('300', $shard2Keys);
    }

    public function test_it_returns_empty_array_for_shard_with_no_keys(): void
    {
        $keys = $this->locator->getKeysForShard('users', 'empty_shard');
        $this->assertIsArray($keys);
        $this->assertEmpty($keys);
    }

    public function test_it_handles_key_overwrite(): void
    {
        // Register key to shard1
        $this->locator->register('users', 123, 'shard1');
        $this->assertEquals('shard1', $this->locator->locate('users', 123));
        
        // Register same key to shard2 (overwrite)
        $this->locator->register('users', 123, 'shard2');
        $this->assertEquals('shard2', $this->locator->locate('users', 123));
        
        // Key should be in shard2's key set
        $shard2Keys = $this->locator->getKeysForShard('users', 'shard2');
        $this->assertContains('123', $shard2Keys);

        // Key should be removed from shard1's key set when the authoritative mapping changes.
        $shard1Keys = $this->locator->getKeysForShard('users', 'shard1');
        $this->assertNotContains('123', $shard1Keys);
    }

    public function test_it_persists_authoritative_mappings_without_expiration(): void
    {
        $this->locator->register('users', 777, 'shard1');

        $ttl = $this->app->make('redis')->connection()->command('ttl', ['shard:users:777']);

        $this->assertSame(-1, $ttl);
        $this->assertSame('shard1', $this->locator->locate('users', 777));
    }
}
