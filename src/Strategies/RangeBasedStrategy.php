<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Strategies;

use Laravel\RedisShard\Contracts\ShardStrategyInterface;

class RangeBasedStrategy implements ShardStrategyInterface
{
    /**
     * The range size for each shard.
     *
     * @var int
     */
    protected int $rangeSize;

    /**
     * Create a new range-based strategy instance.
     *
     * @param int $rangeSize
     */
    public function __construct(int $rangeSize = 1000000)
    {
        $this->rangeSize = $rangeSize;
    }

    /**
     * {@inheritdoc}
     */
    public function determine(string $table, mixed $key, array $availableShards): string
    {
        if (empty($availableShards)) {
            throw new \InvalidArgumentException('No available shards');
        }

        // Convert key to numeric value
        $numericKey = $this->getNumericValue($key);

        // Determine which range the key falls into
        $rangeIndex = (int) floor($numericKey / $this->rangeSize);

        // Map the range index to a shard index
        $shardIndex = $rangeIndex % count($availableShards);

        // Return the shard at that index
        return $availableShards[$shardIndex];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'range_based';
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
            return abs(crc32($key));
        }

        // For other types, convert to string first
        return abs(crc32((string) $key));
    }

    /**
     * Set the range size.
     *
     * @param int $rangeSize
     * @return $this
     */
    public function setRangeSize(int $rangeSize): self
    {
        $this->rangeSize = $rangeSize;

        return $this;
    }

    /**
     * Get the range size.
     *
     * @return int
     */
    public function getRangeSize(): int
    {
        return $this->rangeSize;
    }
}
