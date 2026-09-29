<?php

declare(strict_types=1);

namespace Laravel\RedisShard;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Contracts\ShardStrategyInterface;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Support\ShardRegistry;

class ShardManager
{
    /**
     * @var array<string, ShardStrategyInterface>
     */
    protected array $strategies = [];

    /**
     * @var ShardStrategyInterface|null
     */
    protected ?ShardStrategyInterface $defaultStrategy = null;

    /**
     * Create a new ShardManager instance.
     *
     * @param Repository $config
     * @param DatabaseManager $db
     * @param ShardLocatorInterface $locator
     */
    public function __construct(
        protected Repository $config,
        protected DatabaseManager $db,
        protected ShardLocatorInterface $locator,
        protected ?Container $container = null
    ) {
        $this->hydrateConnectionsFromRegistry();
        $this->registerStrategies();
    }

    /**
     * Load persisted shard connections into runtime config.
     */
    protected function hydrateConnectionsFromRegistry(): void
    {
        $persistedConnections = ShardRegistry::readAll();
        if (empty($persistedConnections)) {
            return;
        }

        $configuredConnections = $this->config->get('redis_sharding.connections', []);
        $this->config->set(
            'redis_sharding.connections',
            array_merge($persistedConnections, $configuredConnections)
        );
    }

    /**
     * Register the sharding strategies from the configuration.
     */
    protected function registerStrategies(): void
    {
        $strategies = $this->config->get('redis_sharding.strategies', []);
        $defaultStrategyName = $this->config->get('redis_sharding.default_strategy');

        foreach ($strategies as $name => $class) {
            try {
                $strategy = $this->container !== null
                    ? $this->container->make($class)
                    : new $class();
            } catch (\Throwable $e) {
                throw new ShardingException("Failed to initialize sharding strategy '{$class}': {$e->getMessage()}");
            }

            if (! $strategy instanceof ShardStrategyInterface) {
                throw new ShardingException("Sharding strategy '{$class}' must implement ShardStrategyInterface");
            }

            $this->strategies[$name] = $strategy;

            if ($name === $defaultStrategyName) {
                $this->defaultStrategy = $strategy;
            }
        }

        if ($this->defaultStrategy === null && ! empty($this->strategies)) {
            $this->defaultStrategy = reset($this->strategies);
        }
    }

    /**
     * Get the shard connection for a specific record.
     *
     * @param string $table
     * @param mixed $key
     * @return string
     * @throws ShardingException
     */
    public function getShardConnection(string $table, mixed $key): string
    {
        // Check if the key is already assigned to a shard
        $shardConnection = $this->locator->locate($table, $key);

        if ($shardConnection !== null) {
            return $shardConnection;
        }

        // If not, determine which shard to use
        $availableShards = $this->getAvailableShards();

        if (empty($availableShards)) {
            throw new ShardingException('No available shards found');
        }

        if ($this->defaultStrategy === null) {
            throw new ShardingException('No default sharding strategy configured');
        }

        $shardConnection = $this->defaultStrategy->determine($table, $key, $availableShards);

        // Register the key to the selected shard
        $this->locator->register($table, $key, $shardConnection);

        return $shardConnection;
    }

    /**
     * Get all available shard connections.
     *
     * @return array<string>
     */
    public function getAvailableShards(): array
    {
        return array_keys($this->config->get('redis_sharding.connections', []));
    }

    /**
     * Create a new shard.
     *
     * @param string $name
     * @param array<string, mixed> $config
     * @return bool
     */
    public function createShard(string $name, array $config): bool
    {
        $connections = $this->config->get('redis_sharding.connections', []);
        $databaseConnections = $this->config->get('database.connections', []);
        $originalConnections = $connections;
        $originalDatabaseConnections = $databaseConnections;
        $persistedRegistry = ShardRegistry::readAll();

        if (isset($connections[$name]) || isset($databaseConnections[$name])) {
            return false;
        }

        // Add the connection to the config
        $connections[$name] = $config;
        $this->config->set('redis_sharding.connections', $connections);
        $this->config->set("database.connections.{$name}", $config);
        ShardRegistry::upsert($name, $config);

        // Reset cached connection state so the new shard can be used immediately.
        try {
            $this->db->purge($name);
        } catch (\Throwable $e) {
            // Ignore purge failures for brand-new connections.
        }

        try {
            ShardMetadata::query()->create([
                'name' => $name,
                'connection' => $name,
                'created_at' => now(),
                'status' => 'active',
            ]);
        } catch (\Throwable $e) {
            $this->config->set('redis_sharding.connections', $originalConnections);
            $this->config->set('database.connections', $originalDatabaseConnections);
            ShardRegistry::writeAll($persistedRegistry);

            try {
                $this->db->purge($name);
            } catch (\Throwable) {
                // Ignore cleanup failures while unwinding shard creation.
            }

            throw new ShardingException(
                "Failed to create shard '{$name}': {$e->getMessage()}",
                0,
                $e
            );
        }

        return true;
    }

    /**
     * Get a specific sharding strategy.
     *
     * @param string|null $name
     * @return ShardStrategyInterface
     * @throws ShardingException
     */
    public function strategy(?string $name = null): ShardStrategyInterface
    {
        if ($name === null) {
            if ($this->defaultStrategy === null) {
                throw new ShardingException('No default sharding strategy configured');
            }

            return $this->defaultStrategy;
        }

        if (! isset($this->strategies[$name])) {
            throw new ShardingException("Sharding strategy '{$name}' not found");
        }

        return $this->strategies[$name];
    }

    /**
     * Get all registered strategies.
     *
     * @return Collection<string, ShardStrategyInterface>
     */
    public function strategies(): Collection
    {
        return collect($this->strategies);
    }
}
