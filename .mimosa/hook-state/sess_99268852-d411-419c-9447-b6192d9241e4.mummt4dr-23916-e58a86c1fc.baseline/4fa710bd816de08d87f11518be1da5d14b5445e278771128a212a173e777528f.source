<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Locators;

use Laravel\RedisShard\Contracts\ShardLocatorInterface;

/**
 * Process-local shard mapping store. Useful when the Redis module is disabled
 * but sticky mappings within a single worker/request are still wanted.
 */
class ArrayShardLocator implements ShardLocatorInterface
{
    /**
     * @var array<string, string>
     */
    protected array $mappings = [];

    /**
     * @var array<string, array<string, true>>
     */
    protected array $shardKeys = [];

    public function locate(string $table, mixed $key): ?string
    {
        return $this->mappings[$this->mappingKey($table, $key)] ?? null;
    }

    public function register(string $table, mixed $key, string $shardConnection): bool
    {
        $this->mappings[$this->mappingKey($table, $key)] = $shardConnection;
        $this->shardKeys[$shardConnection][$this->mappingKey($table, $key)] = true;

        return true;
    }

    public function forget(string $table, mixed $key): bool
    {
        $mappingKey = $this->mappingKey($table, $key);
        $connection = $this->mappings[$mappingKey] ?? null;

        unset($this->mappings[$mappingKey]);

        if ($connection !== null) {
            unset($this->shardKeys[$connection][$mappingKey]);
        }

        return true;
    }

    public function getKeysForShard(string $table, string $shardConnection): array
    {
        $prefix = $table . ':';
        $keys = [];

        foreach (array_keys($this->shardKeys[$shardConnection] ?? []) as $mappingKey) {
            if (str_starts_with($mappingKey, $prefix)) {
                $keys[] = substr($mappingKey, strlen($prefix));
            }
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->mappings;
    }

    protected function mappingKey(string $table, mixed $key): string
    {
        return $table . ':' . (string) $key;
    }
}
