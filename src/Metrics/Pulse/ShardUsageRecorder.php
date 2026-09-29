<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics\Pulse;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Laravel\Pulse\Facades\Pulse;

/**
 * Records the per-request shard routing distribution into Pulse as
 * `shard_request` entries keyed by shard connection. Enabled when Laravel
 * Pulse is installed; see ShardUsageCard for the dashboard card.
 */
class ShardUsageRecorder
{
    public function __construct(protected RequestShardUsage $usage)
    {
    }

    /**
     * @return list<class-string>
     */
    public function listen(): array
    {
        return [RequestHandled::class];
    }

    public function record(RequestHandled $event): void
    {
        foreach ($this->usage->flush() as $connection => $count) {
            Pulse::record('shard_request', $connection, $count);
        }
    }
}
