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
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode($connections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
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
}
