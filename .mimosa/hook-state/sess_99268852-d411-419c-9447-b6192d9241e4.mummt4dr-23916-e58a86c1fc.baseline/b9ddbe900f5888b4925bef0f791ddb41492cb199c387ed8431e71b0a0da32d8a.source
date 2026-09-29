<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Contracts;

interface ShardStrategyInterface
{
    /**
     * Determine which shard a new record should be placed on.
     *
     * @param string $table The table name
     * @param mixed $key The primary key or shard key
     * @param array<string> $availableShards List of available shard connection names
     * @return string The selected shard connection name
     */
    public function determine(string $table, mixed $key, array $availableShards): string;

    /**
     * Get the name of the strategy.
     *
     * @return string
     */
    public function getName(): string;
}
