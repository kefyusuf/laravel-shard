<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Config;

class ShardConnection implements ConnectionResolverInterface
{
    /**
     * The active connection instances.
     *
     * @var array<string, ConnectionInterface>
     */
    protected array $connections = [];

    /**
     * The database connection factory instance.
     *
     * @var \Illuminate\Database\Connectors\ConnectionFactory
     */
    protected $factory;

    /**
     * Create a new shard connection resolver instance.
     *
     * @param \Illuminate\Database\Connectors\ConnectionFactory $factory
     * @return void
     */
    public function __construct($factory)
    {
        $this->factory = $factory;
    }

    /**
     * Get a database connection instance.
     *
     * @param string|null $name
     * @return ConnectionInterface
     */
    public function connection(?string $name = null): ConnectionInterface
    {
        if ($name === null) {
            $name = $this->getDefaultConnection();
        }

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->makeConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Make a new database connection.
     *
     * @param string $name
     * @return ConnectionInterface
     */
    protected function makeConnection(string $name): ConnectionInterface
    {
        $config = $this->getConnectionConfig($name);

        return $this->factory->make($config, $name);
    }

    /**
     * Get the configuration for a connection.
     *
     * @param string $name
     * @return array<string, mixed>
     */
    protected function getConnectionConfig(string $name): array
    {
        $connections = Config::get('redis_sharding.connections', []);

        if (!isset($connections[$name])) {
            throw new \InvalidArgumentException("Shard connection [{$name}] not configured.");
        }

        return $connections[$name];
    }

    /**
     * Get the default connection name.
     *
     * @return string
     */
    public function getDefaultConnection(): string
    {
        return Config::get('database.default');
    }

    /**
     * Set the default connection name.
     *
     * @param string $name
     * @return void
     */
    public function setDefaultConnection(string $name): void
    {
        Config::set('database.default', $name);
    }

    /**
     * Disconnect from the given database and remove from local cache.
     *
     * @param string|null $name
     * @return void
     */
    public function purge(?string $name = null): void
    {
        $name = $name ?: $this->getDefaultConnection();

        if (isset($this->connections[$name])) {
            if ($this->connections[$name] instanceof Connection) {
                $this->connections[$name]->disconnect();
            }

            unset($this->connections[$name]);
        }
    }
}
