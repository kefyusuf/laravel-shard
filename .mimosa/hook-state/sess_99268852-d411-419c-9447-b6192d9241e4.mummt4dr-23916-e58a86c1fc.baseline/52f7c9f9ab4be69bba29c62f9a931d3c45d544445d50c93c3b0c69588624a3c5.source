<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Queue;

use Laravel\RedisShard\Queue\ShardContext;
use Laravel\RedisShard\Tests\TestCase;

class ShardContextTest extends TestCase
{
    public function test_array_roundtrip(): void
    {
        $context = new ShardContext('users', 42, 'shard1', 'id');

        $restored = ShardContext::fromArray($context->toArray());

        $this->assertNotNull($restored);
        $this->assertSame('users', $restored->table);
        $this->assertSame(42, $restored->key);
        $this->assertSame('shard1', $restored->connection);
        $this->assertSame('id', $restored->shardKey);
    }

    public function test_from_array_rejects_incomplete_payload(): void
    {
        $this->assertNull(ShardContext::fromArray([]));
        $this->assertNull(ShardContext::fromArray(['table' => 'users']));
    }
}
