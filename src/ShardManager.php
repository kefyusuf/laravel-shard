<?php

declare(strict_types=1);

namespace Laravel\RedisShard;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Contracts\ShardStrategyInterface;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Metrics\Pulse\RequestShardUsage;
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
        protected ?Container $container = null,
        protected ?RequestShardUsage $usage = null
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

        if ($shardConnection === null) {
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
        }

        $this->usage?->record($shardConnection);

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
     * Resolve the read connection for a shard: the configured read replica
     * when one is mapped, otherwise the shard connection itself.
     */
    public function readConnectionFor(string $shardConnection): string
    {
        $resolver = $this->container !== null
            ? $this->container->make(\Laravel\RedisShard\Database\ReadReplicaResolver::class)
            : new \Laravel\RedisShard\Database\ReadReplicaResolver($this->config);

        return $resolver->resolve($shardConnection);
    }

    /**
     * Run a callback inside a transaction opened on every given shard.
     *
     * This is best-effort coordination, not a distributed two-phase commit:
     * all shards either reach the callback or none do, and a callback
     * failure rolls back every shard — but if a COMMIT itself fails midway,
     * the shards committed before it stay committed. Design writes to be
     * reconcilable (see docs/TRANSACTIONS.md).
     *
     * @param array<string> $shardConnections
     * @param Closure(): mixed $callback
     * @return mixed the callback result
     * @throws ShardingException when no shard is given or a shard cannot begin
     */
    public function transaction(array $shardConnections, Closure $callback): mixed
    {
        $connections = array_values(array_unique($shardConnections));

        if ($connections === []) {
            throw new ShardingException('Cross-shard transactions require at least one shard connection.');
        }

        $begun = [];

        try {
            foreach ($connections as $connection) {
                $this->db->connection($connection)->beginTransaction();
                $begun[] = $connection;
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($begun) as $connection) {
                $this->db->connection($connection)->rollBack();
            }

            throw new ShardingException(sprintf(
                'Cross-shard transaction could not begin on "%s": %s',
                end($connections),
                $e->getMessage()
            ), 0, $e);
        }

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            foreach (array_reverse($begun) as $connection) {
                $this->db->connection($connection)->rollBack();
            }

            throw $e;
        }

        foreach ($begun as $index => $connection) {
            try {
                $this->db->connection($connection)->commit();
            } catch (\Throwable $e) {
                foreach (array_slice($begun, $index + 1) as $remaining) {
                    $this->db->connection($remaining)->rollBack();
                }

                throw new ShardingException(sprintf(
                    'Cross-shard transaction commit failed on "%s"; earlier shards are already committed: %s',
                    $connection,
                    $e->getMessage()
                ), 0, $e);
            }
        }

        return $result;
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
