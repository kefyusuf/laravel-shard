<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Strategies;

use Laravel\RedisShard\Contracts\ShardStrategyInterface;

class ConsistentHashingStrategy implements ShardStrategyInterface
{
    /**
     * The number of virtual nodes per shard.
     *
     * @var int
     */
    protected int $virtualNodes = 256;

    /**
     * The hash ring.
     *
     * @var array<int, string>
     */
    protected array $ring = [];

    /**
     * {@inheritdoc}
     */
    public function determine(string $table, mixed $key, array $availableShards): string
    {
        if (empty($availableShards)) {
            throw new \InvalidArgumentException('No available shards');
        }

        // Build the hash ring if it's empty
        if (empty($this->ring)) {
            $this->buildRing($availableShards);
        }

        // Get the hash value for the key
        $hash = $this->hash($table . ':' . $key);

        // Find the next highest hash in the ring
        $ringKeys = array_keys($this->ring);
        sort($ringKeys);

        foreach ($ringKeys as $ringKey) {
            if ($hash <= $ringKey) {
                return $this->ring[$ringKey];
            }
        }

        // If we get here, we've wrapped around the ring
        return $this->ring[$ringKeys[0]];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'consistent_hashing';
    }

    /**
     * Build the hash ring.
     *
     * @param array<string> $shards
     * @return void
     */
    protected function buildRing(array $shards): void
    {
        $this->ring = [];

        foreach ($shards as $shard) {
            for ($i = 0; $i < $this->virtualNodes; $i++) {
                $virtualNodeKey = $shard . ':' . $i;
                $hash = $this->hash($virtualNodeKey);
                $this->ring[$hash] = $shard;
            }
        }

        ksort($this->ring);
    }

    /**
     * Hash a value.
     *
     * @param string $value
     * @return int
     */
    protected function hash(string $value): int
    {
        return crc32($value);
    }
}
