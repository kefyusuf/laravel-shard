<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Metrics\Pulse;

use Laravel\RedisShard\Metrics\Pulse\RequestShardUsage;
use Laravel\RedisShard\Tests\TestCase;

class RequestShardUsageTest extends TestCase
{
    public function test_it_accumulates_lookups_per_connection(): void
    {
        $usage = new RequestShardUsage();

        $usage->record('shard1');
        $usage->record('shard2');
        $usage->record('shard1');

        $this->assertSame(['shard1' => 2, 'shard2' => 1], $usage->flush());
    }

    public function test_flush_resets_the_counts(): void
    {
        $usage = new RequestShardUsage();
        $usage->record('shard1');

        $usage->flush();

        $this->assertSame([], $usage->flush());
    }
}
