<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Strategies;

use Laravel\RedisShard\Contracts\ShardStrategyInterface;

class ModuloStrategy implements ShardStrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function determine(string $table, mixed $key, array $availableShards): string
    {
        if (empty($availableShards)) {
            throw new \InvalidArgumentException('No available shards');
        }

        // Convert key to numeric value for modulo operation
        $numericKey = $this->getNumericValue($key);
        
        // Use modulo to determine shard index
        $shardIndex = $numericKey % count($availableShards);
        
        // Return the shard at that index
        return $availableShards[$shardIndex];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'modulo';
    }

    /**
     * Convert a key to a numeric value.
     *
     * @param mixed $key
     * @return int
     */
    protected function getNumericValue(mixed $key): int
    {
        if (is_numeric($key)) {
            return (int) $key;
        }

        if (is_string($key)) {
            // Use crc32 hash for string keys
            return crc32($key);
        }

        // For other types, convert to string first
        return crc32((string) $key);
    }
}
