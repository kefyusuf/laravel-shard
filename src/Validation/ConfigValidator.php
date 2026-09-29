<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Validation;

use Laravel\RedisShard\Exceptions\ConfigurationException;

class ConfigValidator
{
    /**
     * Validate the Redis sharding configuration.
     *
     * @param array $config
     * @throws ConfigurationException
     * @return void
     */
    public static function validate(array $config): void
    {
        static::validateRedisConnection($config);
        static::validateStrategies($config);
        static::validateConnections($config);
        static::validateAutoProvisioning($config);
        static::validateCacheTtl($config);
    }

    /**
     * Validate Redis connection configuration.
     *
     * @param array $config
     * @throws ConfigurationException
     * @return void
     */
    protected static function validateRedisConnection(array $config): void
    {
        if (! isset($config['redis_connection'])) {
            throw new ConfigurationException('Redis connection name is required in redis_sharding.redis_connection');
        }

        if (! is_string($config['redis_connection']) || empty($config['redis_connection'])) {
            throw new ConfigurationException('Redis connection name must be a non-empty string');
        }

        // Check if the Redis connection exists in Laravel's database config
        $redisConfig = config('database.redis');
        if (! isset($redisConfig[$config['redis_connection']])) {
            throw new ConfigurationException(
                "Redis connection '{$config['redis_connection']}' is not configured in database.redis"
            );
        }
    }

    /**
     * Validate sharding strategies configuration.
     *
     * @param array $config
     * @throws ConfigurationException
     * @return void
     */
    protected static function validateStrategies(array $config): void
    {
        if (! isset($config['strategies']) || ! is_array($config['strategies'])) {
            throw new ConfigurationException('Strategies configuration must be an array');
        }

        if (empty($config['strategies'])) {
            throw new ConfigurationException('At least one sharding strategy must be configured');
        }

        foreach ($config['strategies'] as $name => $class) {
            if (! is_string($name) || empty($name)) {
                throw new ConfigurationException('Strategy names must be non-empty strings');
            }

            if (! is_string($class) || empty($class)) {
                throw new ConfigurationException("Strategy class for '{$name}' must be a non-empty string");
            }

            if (! class_exists($class)) {
                throw new ConfigurationException("Strategy class '{$class}' does not exist");
            }

            if (! in_array('Laravel\RedisShard\Contracts\ShardStrategyInterface', class_implements($class) ?: [])) {
                throw new ConfigurationException(
                    "Strategy class '{$class}' must implement ShardStrategyInterface"
                );
            }
        }

        // Validate default strategy
        if (! isset($config['default_strategy'])) {
            throw new ConfigurationException('Default strategy must be specified in redis_sharding.default_strategy');
        }

        if (! isset($config['strategies'][$config['default_strategy']])) {
            throw new ConfigurationException(
                "Default strategy '{$config['default_strategy']}' is not defined in strategies array"
            );
        }
    }

    /**
     * Validate shard connections configuration.
     *
     * @param array $config
     * @throws ConfigurationException
     * @return void
     */
    protected static function validateConnections(array $config): void
    {
        if (! isset($config['connections']) || ! is_array($config['connections'])) {
            throw new ConfigurationException('Connections configuration must be an array');
        }

        if (empty($config['connections'])) {
            return;
        }

        foreach ($config['connections'] as $name => $connectionConfig) {
            if (! is_string($name) || empty($name)) {
                throw new ConfigurationException('Connection names must be non-empty strings');
            }

            if (! is_array($connectionConfig)) {
                throw new ConfigurationException("Connection configuration for '{$name}' must be an array");
            }

            static::validateConnectionConfig($name, $connectionConfig);
        }
    }

    /**
     * Validate individual connection configuration.
     *
     * @param string $name
     * @param array $config
     * @throws ConfigurationException
     * @return void
     */
    protected static function validateConnectionConfig(string $name, array $config): void
    {
        $driver = $config['driver'] ?? null;
        if (! is_string($driver) || $driver === '') {
            throw new ConfigurationException(
                "Connection '{$name}' is missing required field 'driver'"
            );
        }

        $requiredFields = ['database'];
        if ($driver !== 'sqlite') {
            $requiredFields = ['host', 'database', 'username'];
        }

        foreach ($requiredFields as $field) {
            if (! isset($config[$field])) {
                throw new ConfigurationException(
                    "Connection '{$name}' is missing required field '{$field}'"
                );
            }

            if (! is_string($config[$field]) && ! is_numeric($config[$field])) {
                throw new ConfigurationException(
                    "Connection '{$name}' field '{$field}' must be a string or number"
                );
            }
        }

        // Validate driver
        $supportedDrivers = ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];
        if (! in_array($driver, $supportedDrivers, true)) {
            throw new ConfigurationException(
                "Connection '{$name}' has unsupported driver '{$driver}'. " .
                "Supported drivers: " . implode(', ', $supportedDrivers)
            );
        }

        // Validate port if specified
        if ($driver !== 'sqlite' && isset($config['port'])) {
            $port = (int) $config['port'];
            if ($port < 1 || $port > 65535) {
                throw new ConfigurationException(
                    "Connection '{$name}' has invalid port '{$config['port']}'. Must be between 1 and 65535"
                );
            }
        }
    }

    /**
     * Validate auto-provisioning configuration.
     *
     * @param array $config
     * @throws ConfigurationException
     * @return void
     */
    protected static function validateAutoProvisioning(array $config): void
    {
        if (! isset($config['auto_provisioning'])) {
            return; // Auto-provisioning is optional
        }

        $autoConfig = $config['auto_provisioning'];

        if (! is_array($autoConfig)) {
            throw new ConfigurationException('Auto-provisioning configuration must be an array');
        }

        if (isset($autoConfig['enabled']) && ! is_bool($autoConfig['enabled'])) {
            throw new ConfigurationException('Auto-provisioning enabled flag must be a boolean');
        }

        if (isset($autoConfig['max_shards'])) {
            $maxShards = (int) $autoConfig['max_shards'];
            if ($maxShards < 1) {
                throw new ConfigurationException('Auto-provisioning max_shards must be at least 1');
            }
        }

        if (isset($autoConfig['threshold'])) {
            $threshold = (int) $autoConfig['threshold'];
            if ($threshold < 1) {
                throw new ConfigurationException('Auto-provisioning threshold must be at least 1');
            }
        }
    }

    /**
     * Validate cache TTL configuration.
     *
     * @param array $config
     * @throws ConfigurationException
     * @return void
     */
    protected static function validateCacheTtl(array $config): void
    {
        if (! isset($config['cache_ttl'])) {
            return; // Cache TTL is optional
        }

        $ttl = (int) $config['cache_ttl'];
        if ($ttl < 0) {
            throw new ConfigurationException('Cache TTL must be 0 or greater (0 means no expiration)');
        }
    }

    /**
     * Get configuration recommendations.
     *
     * @param array $config
     * @return array
     */
    public static function getRecommendations(array $config): array
    {
        $recommendations = [];

        // Check shard count
        $shardCount = count($config['connections'] ?? []);
        if ($shardCount < 2) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Consider configuring at least 2 shards for better distribution and redundancy',
            ];
        }

        // Check cache TTL
        $cacheTtl = $config['cache_ttl'] ?? 3600;
        if ($cacheTtl > 86400) { // 24 hours
            $recommendations[] = [
                'type' => 'info',
                'message' => 'Cache TTL is set to more than 24 hours. Consider shorter TTL for better consistency',
            ];
        }

        // Check auto-provisioning
        if (! ($config['auto_provisioning']['enabled'] ?? false)) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'Auto-provisioning is disabled. Enable it for automatic scaling',
            ];
        }

        // Check strategy selection
        $defaultStrategy = $config['default_strategy'] ?? '';
        if ($defaultStrategy === 'modulo' && $shardCount > 5) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Modulo strategy with many shards can cause uneven distribution. Consider consistent_hashing',
            ];
        }

        return $recommendations;
    }
}
