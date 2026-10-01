<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Metrics\Pulse;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Laravel\Pulse\Facades\Pulse;
use Laravel\RedisShard\Metrics\Pulse\RequestShardUsage;
use Laravel\RedisShard\Metrics\Pulse\ShardUsageRecorder;
use Laravel\RedisShard\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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

    public function test_it_flushes_before_each_queue_job_loop(): void
    {
        Pulse::shouldReceive('record')->once()->with('shard_request', 'shard1', 3);

        $usage = new RequestShardUsage();
        $usage->record('shard1');
        $usage->record('shard1');
        $usage->record('shard1');

        (new ShardUsageRecorder($usage))->record(new Looping('redis', 'default'));

        $this->assertSame([], $usage->flush());
    }

    public function test_it_flushes_when_the_worker_stops(): void
    {
        Pulse::shouldReceive('record')->once()->with('shard_request', 'shard2', 1);

        $usage = new RequestShardUsage();
        $usage->record('shard2');

        (new ShardUsageRecorder($usage))->record(new WorkerStopping());

        $this->assertSame([], $usage->flush());
    }

    public function test_it_flushes_after_console_commands(): void
    {
        Pulse::shouldReceive('record')->once()->with('shard_request', 'shard1', 1);

        $usage = new RequestShardUsage();
        $usage->record('shard1');

        (new ShardUsageRecorder($usage))->record(new CommandFinished(
            'shard:health',
            new ArrayInput([]),
            new NullOutput(),
            0,
            1.2,
        ));

        $this->assertSame([], $usage->flush());
    }

    public function test_it_flushes_on_any_lifecycle_event_such_as_octane_operations(): void
    {
        Pulse::shouldReceive('record')->once()->with('shard_request', 'shard1', 1);

        $usage = new RequestShardUsage();
        $usage->record('shard1');

        // Octane's OperationTerminated can only be instantiated when the
        // package is installed; the recorder treats any lifecycle event alike.
        (new ShardUsageRecorder($usage))->record((object) ['name' => 'octane']);

        $this->assertSame([], $usage->flush());
    }

    public function test_it_records_nothing_when_no_shard_was_used(): void
    {
        Pulse::shouldReceive('record')->never();

        $recorder = new ShardUsageRecorder(new RequestShardUsage());
        $recorder->record(new RequestHandled(Request::create('/'), null));
    }

    public function test_it_listens_to_http_queue_and_console_lifecycle_events(): void
    {
        $events = (new ShardUsageRecorder(new RequestShardUsage()))->listen();

        $this->assertContains(RequestHandled::class, $events);
        $this->assertContains(Looping::class, $events);
        $this->assertContains(WorkerStopping::class, $events);
        $this->assertContains(CommandFinished::class, $events);
        $this->assertContains('Laravel\Octane\Events\OperationTerminated', $events);
    }
}
