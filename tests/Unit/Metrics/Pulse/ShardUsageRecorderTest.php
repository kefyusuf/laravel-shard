<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Metrics\Pulse;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Laravel\Pulse\Facades\Pulse;
use Laravel\RedisShard\Metrics\Pulse\RequestShardUsage;
use Laravel\RedisShard\Metrics\Pulse\ShardUsageRecorder;
use Laravel\RedisShard\Tests\TestCase;

class ShardUsageRecorderTest extends TestCase
{
    public function test_it_records_one_entry_per_used_connection_on_request_handled(): void
    {
        Pulse::shouldReceive('record')->once()->with('shard_request', 'shard1', 2);
        Pulse::shouldReceive('record')->once()->with('shard_request', 'shard2', 1);

        $usage = new RequestShardUsage();
        $usage->record('shard1');
        $usage->record('shard1');
        $usage->record('shard2');

        $recorder = new ShardUsageRecorder($usage);
        $recorder->record(new RequestHandled(Request::create('/'), null));

        $this->assertSame([], $usage->flush());
    }

    public function test_it_records_nothing_when_no_shard_was_used(): void
    {
        Pulse::shouldReceive('record')->never();

        $recorder = new ShardUsageRecorder(new RequestShardUsage());
        $recorder->record(new RequestHandled(Request::create('/'), null));
    }

    public function test_it_listens_to_request_handled_events(): void
    {
        $recorder = new ShardUsageRecorder(new RequestShardUsage());

        $this->assertSame([RequestHandled::class], $recorder->listen());
    }
}
