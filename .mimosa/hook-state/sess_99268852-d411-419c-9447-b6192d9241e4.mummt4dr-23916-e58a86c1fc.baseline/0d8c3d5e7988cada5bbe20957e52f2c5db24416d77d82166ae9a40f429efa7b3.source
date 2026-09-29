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
     * Sorted hash ring keys.
     *
     * @var array<int, int>
     */
    protected array $ringKeys = [];

    protected ?string $topologySignature = null;

    /**
     * {@inheritdoc}
     */
    public function determine(string $table, mixed $key, array $availableShards): string
    {
        if (empty($availableShards)) {
            throw new \InvalidArgumentException('No available shards');
        }

        $this->ensureRing($availableShards);

        $hash = $this->hash($table . ':' . $key);
        $index = $this->findRingIndex($hash);

        return $this->ring[$this->ringKeys[$index]];
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
        $this->ringKeys = array_keys($this->ring);
    }

    protected function ensureRing(array $availableShards): void
    {
        $normalizedShards = array_values($availableShards);
        sort($normalizedShards);

        $signature = implode('|', $normalizedShards);

        if ($signature === $this->topologySignature && $this->ringKeys !== []) {
            return;
        }

        $this->buildRing($normalizedShards);
        $this->topologySignature = $signature;
    }

    protected function findRingIndex(int $hash): int
    {
        $low = 0;
        $high = count($this->ringKeys) - 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $ringKey = $this->ringKeys[$mid];

            if ($ringKey < $hash) {
                $low = $mid + 1;
                continue;
            }

            $high = $mid - 1;
        }

        return $low < count($this->ringKeys) ? $low : 0;
    }

    /**
     * Hash a value.
     *
     * @param string $value
     * @return int
     */
    protected function hash(string $value): int
    {
        return (int) sprintf('%u', crc32($value));
    }
}
