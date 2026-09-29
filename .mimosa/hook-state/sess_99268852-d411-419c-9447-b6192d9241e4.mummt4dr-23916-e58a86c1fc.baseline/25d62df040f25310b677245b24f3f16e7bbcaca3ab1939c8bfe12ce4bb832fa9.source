<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Support;

class ShardRegistry
{
    /**
     * Persist all shard connection definitions.
     *
     * @param array<string, array<string, mixed>> $connections
     */
    public static function writeAll(array $connections): void
    {
        $path = static::resolvePath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        } else {
            @chmod($directory, 0700);
        }

        $payload = json_encode($connections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new \RuntimeException('Failed to encode shard registry payload.');
        }

        static::withFileLock($path, LOCK_EX, function () use ($path, $payload): void {
            $tempPath = $path . '.tmp.' . uniqid('', true);

            $bytes = file_put_contents($tempPath, $payload);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to write temporary shard registry file.');
            }

            @chmod($tempPath, 0640);

            if (!@rename($tempPath, $path)) {
                @unlink($path);
                if (!@rename($tempPath, $path)) {
                    @unlink($tempPath);
                    throw new \RuntimeException('Failed to atomically write shard registry file.');
                }
            }
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function readAll(): array
    {
        $path = static::resolvePath();

        if (!is_file($path)) {
            return [];
        }

        $contents = static::withFileLock($path, LOCK_SH, function () use ($path): string {
            $contents = file_get_contents($path);

            return is_string($contents) ? $contents : '';
        });

        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, 'is_array');
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
            if (!flock($lockHandle, $lockType)) {
                throw new \RuntimeException('Unable to acquire registry file lock.');
            }

            return $callback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
