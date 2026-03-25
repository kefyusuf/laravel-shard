<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Validation;

use Laravel\RedisShard\Exceptions\ConfigurationException;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Validation\ConfigValidator;

class ConfigValidatorTest extends TestCase
{
    public function test_it_allows_empty_connections_configuration(): void
    {
        config()->set('database.redis.default', ['host' => '127.0.0.1']);

        ConfigValidator::validate([
            'redis_connection' => 'default',
            'default_strategy' => 'modulo',
            'strategies' => [
                'modulo' => \Laravel\RedisShard\Strategies\ModuloStrategy::class,
            ],
            'connections' => [],
        ]);

        $this->assertTrue(true);
    }

    public function test_it_accepts_minimal_sqlite_connection_shape(): void
    {
        config()->set('database.redis.default', ['host' => '127.0.0.1']);

        ConfigValidator::validate([
            'redis_connection' => 'default',
            'default_strategy' => 'modulo',
            'strategies' => [
                'modulo' => \Laravel\RedisShard\Strategies\ModuloStrategy::class,
            ],
            'connections' => [
                'sqlite_shard' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_it_rejects_non_sqlite_connection_without_host(): void
    {
        config()->set('database.redis.default', ['host' => '127.0.0.1']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Connection 'mysql_shard' is missing required field 'host'");

        ConfigValidator::validate([
            'redis_connection' => 'default',
            'default_strategy' => 'modulo',
            'strategies' => [
                'modulo' => \Laravel\RedisShard\Strategies\ModuloStrategy::class,
            ],
            'connections' => [
                'mysql_shard' => [
                    'driver' => 'mysql',
                    'database' => 'app',
                    'username' => 'root',
                ],
            ],
        ]);
    }
}
