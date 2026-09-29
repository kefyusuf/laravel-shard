<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

class ConnectionPool
{
    /**
     * @var DatabaseManager
     */
    protected DatabaseManager $db;

    /**
     * @var array<string, Connection>
     */
    protected array $connections = [];

    /**
     * @var array<string, int>
     */
    protected array $connectionUsage = [];

    /**
     * @var int
     */
    protected int $maxConnections;

    /**
     * @var int
     */
    protected int $connectionTimeout;

    /**
     * Create a new connection pool instance.
     *
     * @param DatabaseManager $db
     * @param int $maxConnections
     * @param int $connectionTimeout
     */
    public function __construct(DatabaseManager $db, int $maxConnections = 10, int $connectionTimeout = 300)
    {
        $this->db = $db;
        $this->maxConnections = $maxConnections;
        $this->connectionTimeout = $connectionTimeout;
    }

    /**
     * Get a connection from the pool.
     *
     * @param string $shardName
     * @return Connection
     */
    public function getConnection(string $shardName): Connection
    {
        // Check if we have a cached connection
        if (isset($this->connections[$shardName])) {
            $connection = $this->connections[$shardName];
            
            // Check if connection is still alive
            if ($this->isConnectionAlive($connection)) {
                $this->connectionUsage[$shardName] = time();
                return $connection;
            } else {
                // Remove dead connection
                unset($this->connections[$shardName]);
                unset($this->connectionUsage[$shardName]);
            }
        }

        // Clean up old connections if we're at the limit
        if (count($this->connections) >= $this->maxConnections) {
            $this->cleanupOldConnections();
        }

        // Create new connection
        $connection = $this->db->connection($shardName);
        $this->connections[$shardName] = $connection;
        $this->connectionUsage[$shardName] = time();

        return $connection;
    }

    /**
     * Release a connection back to the pool.
     *
     * @param string $shardName
     * @return void
     */
    public function releaseConnection(string $shardName): void
    {
        // In a more sophisticated implementation, you might want to
        // track active vs idle connections
        if (isset($this->connectionUsage[$shardName])) {
            $this->connectionUsage[$shardName] = time();
        }
    }

    /**
     * Check if a connection is still alive.
     *
     * @param Connection $connection
     * @return bool
     */
    protected function isConnectionAlive(Connection $connection): bool
    {
        try {
            $connection->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clean up old connections.
     *
     * @return void
     */
    protected function cleanupOldConnections(): void
    {
        $currentTime = time();
        $connectionsToRemove = [];

        foreach ($this->connectionUsage as $shardName => $lastUsed) {
            if (($currentTime - $lastUsed) > $this->connectionTimeout) {
                $connectionsToRemove[] = $shardName;
            }
        }

        foreach ($connectionsToRemove as $shardName) {
            $this->closeConnection($shardName);
        }
    }

    /**
     * Close a specific connection.
     *
     * @param string $shardName
     * @return void
     */
    public function closeConnection(string $shardName): void
    {
        if (isset($this->connections[$shardName])) {
            try {
                $this->connections[$shardName]->disconnect();
            } catch (\Exception $e) {
                // Ignore errors when closing connections
            }
            
            unset($this->connections[$shardName]);
            unset($this->connectionUsage[$shardName]);
        }
    }

    /**
     * Close all connections.
     *
     * @return void
     */
    public function closeAllConnections(): void
    {
        foreach (array_keys($this->connections) as $shardName) {
            $this->closeConnection($shardName);
        }
    }

    /**
     * Get pool statistics.
     *
     * @return array
     */
    public function getStats(): array
    {
        $currentTime = time();
        $activeConnections = 0;
        $idleConnections = 0;

        foreach ($this->connectionUsage as $lastUsed) {
            if (($currentTime - $lastUsed) < 60) { // Active if used in last minute
                $activeConnections++;
            } else {
                $idleConnections++;
            }
        }

        return [
            'total_connections' => count($this->connections),
            'active_connections' => $activeConnections,
            'idle_connections' => $idleConnections,
            'max_connections' => $this->maxConnections,
            'connection_timeout' => $this->connectionTimeout,
            'pool_utilization' => round((count($this->connections) / $this->maxConnections) * 100, 2),
        ];
    }

    /**
     * Test all connections in the pool.
     *
     * @return array
     */
    public function testConnections(): array
    {
        $results = [];

        foreach ($this->connections as $shardName => $connection) {
            $startTime = microtime(true);
            $isAlive = $this->isConnectionAlive($connection);
            $responseTime = (microtime(true) - $startTime) * 1000;

            $results[$shardName] = [
                'alive' => $isAlive,
                'response_time_ms' => round($responseTime, 2),
                'last_used' => $this->connectionUsage[$shardName] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Warm up the connection pool.
     *
     * @param array $shardNames
     * @return void
     */
    public function warmUp(array $shardNames): void
    {
        foreach ($shardNames as $shardName) {
            try {
                $this->getConnection($shardName);
            } catch (\Exception $e) {
                // Log error but continue with other connections
                logger()->warning("Failed to warm up connection for shard {$shardName}: " . $e->getMessage());
            }
        }
    }

    /**
     * Get connection usage information.
     *
     * @return array
     */
    public function getConnectionUsage(): array
    {
        $usage = [];
        $currentTime = time();

        foreach ($this->connectionUsage as $shardName => $lastUsed) {
            $usage[$shardName] = [
                'last_used' => $lastUsed,
                'idle_time' => $currentTime - $lastUsed,
                'is_active' => ($currentTime - $lastUsed) < 60,
            ];
        }

        return $usage;
    }

    /**
     * Set maximum connections.
     *
     * @param int $maxConnections
     * @return void
     */
    public function setMaxConnections(int $maxConnections): void
    {
        $this->maxConnections = $maxConnections;
        
        // Clean up excess connections if needed
        if (count($this->connections) > $maxConnections) {
            $this->cleanupOldConnections();
        }
    }

    /**
     * Set connection timeout.
     *
     * @param int $timeout
     * @return void
     */
    public function setConnectionTimeout(int $timeout): void
    {
        $this->connectionTimeout = $timeout;
    }

    /**
     * Destructor to clean up connections.
     */
    public function __destruct()
    {
        $this->closeAllConnections();
    }
}
