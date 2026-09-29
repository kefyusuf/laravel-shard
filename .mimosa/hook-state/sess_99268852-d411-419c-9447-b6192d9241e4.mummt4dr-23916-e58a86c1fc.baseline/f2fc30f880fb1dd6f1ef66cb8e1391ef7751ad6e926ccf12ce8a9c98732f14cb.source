<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Contracts;

interface RebalanceDataMoverInterface
{
    /**
     * Move a single record between shards.
     *
     * @param string $table
     * @param mixed $key
     * @param string $fromShard
     * @param string $toShard
     */
    public function move(string $table, mixed $key, string $fromShard, string $toShard): bool;
}
