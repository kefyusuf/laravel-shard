<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics\Pulse;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Laravel\Pulse\Facades\Pulse;

/**
 * Records the per-request shard routing distribution into Pulse as
 * `shard_request` entries keyed by shard connection. Enabled when Laravel
 * Pulse is installed; see ShardUsageCard for the dashboard card.
 *
 * Flushing covers every execution context, not just HTTP: between queue
 * job loops, after console commands, on worker shutdown and on Octane
 * operation termination — so a long-running worker never accumulates
 * unbounded counter state.
 */
class ShardUsageRecorder
{
    public function __construct(protected RequestShardUsage $usage)
    {
    }

    /**
     * @return list<string>
     */
    public function listen(): array
    {
        return [
            RequestHandled::class,
            Looping::class,
            WorkerStopping::class,
            CommandFinished::class,
            // Only fired when Octane is installed; string literal so the
            // optional dependency stays out of static analysis.
            'Laravel\Octane\Events\OperationTerminated',
        ];
    }

    /**
     * Every listened lifecycle event triggers the same flush: publish the
     * accumulated per-connection counts and reset the counter.
     *
     * @param object $event
     */
    public function record(object $event): void
    {
        $this->flush();
    }

    protected function flush(): void
    {
        foreach ($this->usage->flush() as $connection => $count) {
            Pulse::record('shard_request', $connection, $count);
        }
    }
}
