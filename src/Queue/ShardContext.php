<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Queue;

/**
 * Immutable shard affinity for queued work.
 */
final class ShardContext
{
    public function __construct(
        public readonly string $table,
        public readonly mixed $key,
        public readonly string $connection,
        public readonly ?string $shardKey = null,
    ) {
    }

    /**
     * @return array{table: string, key: mixed, connection: string, shard_key: ?string}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'key' => $this->key,
            'connection' => $this->connection,
            'shard_key' => $this->shardKey,
        ];
    }

    /**
     * @param array{table?: string, key?: mixed, connection?: string, shard_key?: ?string} $payload
     */
    public static function fromArray(array $payload): ?self
    {
        if (!isset($payload['connection'], $payload['table'])) {
            return null;
        }

        return new self(
            table: (string) $payload['table'],
            key: $payload['key'] ?? null,
            connection: (string) $payload['connection'],
            shardKey: isset($payload['shard_key']) ? (string) $payload['shard_key'] : null,
        );
    }
}
