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
     * @return self
     */
    public static function missingConfig(string $key): self
    {
        return new self("Missing required configuration: {$key}");
    }

    /**
     * Create an exception for invalid configuration value.
     *
     * @param string $key
     * @param mixed $value
     * @param string $expected
     * @return self
     */
    public static function invalidConfig(string $key, mixed $value, string $expected): self
    {
        $actualType = is_object($value) ? get_class($value) : gettype($value);

        return new self("Invalid configuration for {$key}. Expected {$expected}, got {$actualType}");
    }

    /**
     * Create an exception for invalid strategy.
     *
     * @param string $strategy
     * @return self
     */
    public static function invalidStrategy(string $strategy): self
    {
        return new self("Invalid sharding strategy: {$strategy}");
    }

    /**
     * Create an exception for invalid connection.
     *
     * @param string $connection
     * @return self
     */
    public static function invalidConnection(string $connection): self
    {
        return new self("Invalid shard connection: {$connection}");
    }
}
