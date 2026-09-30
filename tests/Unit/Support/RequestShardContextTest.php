<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Support;

use Laravel\RedisShard\Support\RequestShardContext;
use Laravel\RedisShard\Tests\TestCase;

class RequestShardContextTest extends TestCase
{
    public function test_set_then_get_returns_the_connection(): void
    {
        $context = new RequestShardContext();

        $context->set('shard2');

        $this->assertSame('shard2', $context->get());
    }

    public function test_get_normalizes_empty_values_to_null(): void
    {
        $context = new RequestShardContext();

        $context->set('');

        $this->assertNull($context->get());
    }

    public function test_get_returns_null_when_nothing_was_set(): void
    {
        $this->assertNull((new RequestShardContext())->get());
    }

    public function test_set_null_clears_the_attribute(): void
    {
        $context = new RequestShardContext();
        $context->set('shard1');

        $context->set(null);

        $this->assertNull($context->get());
    }
}
