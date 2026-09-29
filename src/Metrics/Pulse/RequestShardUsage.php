<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Metrics\Pulse;

/**
 * Request-scoped counter of shard resolutions, flushed by the Pulse
 * recorder at the end of each request. Kept Pulse-agnostic so the
 * routing hot path never depends on Pulse being installed.
 */
class RequestShardUsage
{
    /**
     * @var array<string, int>
     */
    protected array $counts = [];

    /**
     * Record one resolution against a shard connection.
     */
    public function record(string $connection): void
    {
        $this->counts[$connection] = ($this->counts[$connection] ?? 0) + 1;
    }

    /**
     * Return the accumulated counts and reset the counter.
     *
     * @return array<string, int>
     */
    public function flush(): array
    {
        $counts = $this->counts;
        $this->counts = [];

        return $counts;
    }
}
