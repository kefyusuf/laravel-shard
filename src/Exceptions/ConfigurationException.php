<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Exceptions;

use Exception;

class ConfigurationException extends Exception
{
    /**
     * Create a new configuration exception.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create an exception for missing configuration.
     *
     * @param string $key
     * @return static
     */
    public static function missingConfig(string $key): static
    {
        return new static("Missing required configuration: {$key}");
    }

    /**
     * Create an exception for invalid configuration value.
     *
     * @param string $key
     * @param mixed $value
     * @param string $expected
     * @return static
     */
    public static function invalidConfig(string $key, mixed $value, string $expected): static
    {
        $actualType = is_object($value) ? get_class($value) : gettype($value);
        return new static("Invalid configuration for {$key}. Expected {$expected}, got {$actualType}");
    }

    /**
     * Create an exception for invalid strategy.
     *
     * @param string $strategy
     * @return static
     */
    public static function invalidStrategy(string $strategy): static
    {
        return new static("Invalid sharding strategy: {$strategy}");
    }

    /**
     * Create an exception for invalid connection.
     *
     * @param string $connection
     * @return static
     */
    public static function invalidConnection(string $connection): static
    {
        return new static("Invalid shard connection: {$connection}");
    }
}
