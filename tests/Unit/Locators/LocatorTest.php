<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Locators;

use Laravel\RedisShard\Locators\ArrayShardLocator;
use Laravel\RedisShard\Tests\TestCase;

class LocatorTest extends TestCase
{
    public function test_array_locator_roundtrip(): void
    {
        $locator = new ArrayShardLocator();

        $this->assertNull($locator->locate('users', 10));
        $this->assertTrue($locator->register('users', 10, 'shard1'));
        $this->assertSame('shard1', $locator->locate('users', 10));
        $this->assertSame(['10'], $locator->getKeysForShard('users', 'shard1'));

        $this->assertTrue($locator->forget('users', 10));
        $this->assertNull($locator->locate('users', 10));
    }

    public function test_array_locator_isolates_tables(): void
    {
        $locator = new ArrayShardLocator();
        $locator->register('users', 1, 'shard1');
        $locator->register('orders', 1, 'shard2');

        $this->assertSame('shard1', $locator->locate('users', 1));
        $this->assertSame('shard2', $locator->locate('orders', 1));
    }
}
