<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Queue;

/**
 * Marker for jobs that carry shard affinity.
 */
interface ShardAwareInterface
{
    public function getShardContext(): ?ShardContext;

    public function setShardContext(?ShardContext $context): void;
}
