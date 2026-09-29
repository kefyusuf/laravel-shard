<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Support;

class ShardRegistry
{
    protected const FILE_VERSION = 2;

    /**
     * Persist all shard connection definitions.
     *
     * @param array<string, array<string, mixed>> $connections
     */
    public static function writeAll(array $connections): void
    {
        static::writeDocument($connections, static::readBucketMap());
    }

    /**
     * Persist the virtual-bucket to shard mapping, preserving connections.
     *
     * @param array<int, string> $map bucket number => shard connection
     */
    public static function writeBucketMap(array $map): void
    {
        static::writeDocument(static::readAll(), $map);
    }

    /**
     * Read all persisted shard connection definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function readAll(): array
    {
        return static::readDocument()['connections'];
    }

    /**
     * @return array<int, string> bucket number => shard connection
     */
    public static function readBucketMap(): array
    {
        $document = static::readDocument();

        $map = [];
        foreach ($document['buckets'] as $bucket => $shard) {
            if (is_string($shard) && $shard !== '') {
                $map[(int) $bucket] = $shard;
            }
        }

        return $map;
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     * @param array<int, string> $buckets
     */
    protected static function writeDocument(array $connections, array $buckets): void
    {
        $path = static::resolvePath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        } else {
            @chmod($directory, 0700);
        }

        $payload = json_encode([
            'version' => static::FILE_VERSION,
            'connections' => $connections,
            'buckets' => $buckets,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            throw new \RuntimeException('Failed to encode shard registry payload.');
        }

        static::withFileLock($path, LOCK_EX, function () use ($path, $payload): void {
            $tempPath = $path . '.tmp.' . uniqid('', true);

            $bytes = file_put_contents($tempPath, $payload);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to write temporary shard registry file.');
            }

            @chmod($tempPath, 0640);

            if (! @rename($tempPath, $path)) {
                @unlink($path);
                if (! @rename($tempPath, $path)) {
                    @unlink($tempPath);

                    throw new \RuntimeException('Failed to atomically write shard registry file.');
                }
            }
        });
    }

    /**
     * @return array{connections: array<string, array<string, mixed>>, buckets: array<string, mixed>}
     */
    protected static function readDocument(): array
    {
        $path = static::resolvePath();

        if (! is_file($path)) {
            return ['connections' => [], 'buckets' => []];
        }

        $contents = static::withFileLock($path, LOCK_SH, function () use ($path): string {
            $contents = file_get_contents($path);

            return is_string($contents) ? $contents : '';
        });

        if ($contents === '') {
            return ['connections' => [], 'buckets' => []];
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return ['connections' => [], 'buckets' => []];
        }

        // Version 2 documents carry connections and the bucket map together;
        // legacy files hold the connection map directly.
        if (($decoded['version'] ?? null) === static::FILE_VERSION) {
            $connections = is_array($decoded['connections'] ?? null) ? $decoded['connections'] : [];
            $buckets = is_array($decoded['buckets'] ?? null) ? $decoded['buckets'] : [];

            return [
                'connections' => array_filter($connections, 'is_array'),
                'buckets' => $buckets,
            ];
        }

        return ['connections' => array_filter($decoded, 'is_array'), 'buckets' => []];
    }

    /**
     * @param string $name
     * @param array<string, mixed> $config
     */
    public static function upsert(string $name, array $config): void
    {
        $connections = static::readAll();
        $connections[$name] = $config;
        static::writeAll($connections);
    }

    protected static function resolvePath(): string
    {
        $configuredPath = config('redis_sharding.registry_path');
        if (is_string($configuredPath) && $configuredPath !== '') {
            return $configuredPath;
        }

        if (function_exists('storage_path')) {
            return storage_path('app/redis_sharding_registry.json');
        }

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'redis_sharding_registry.json';
    }

    /**
     * @template T
     *
     * @param callable():T $callback
     * @return T
     */
    protected static function withFileLock(string $path, int $lockType, callable $callback): mixed
    {
        $lockPath = $path . '.lock';
        $lockHandle = fopen($lockPath, 'c');

        if ($lockHandle === false) {
            throw new \RuntimeException("Unable to open registry lock file: {$lockPath}");
        }

        try {
            if (! flock($lockHandle, $lockType)) {
                throw new \RuntimeException('Unable to acquire registry file lock.');
            }

            return $callback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
