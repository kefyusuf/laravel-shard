<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Database;

use Closure;
use Illuminate\Database\DatabaseManager;
use Laravel\RedisShard\Exceptions\ShardingException;

/**
 * Best-effort cross-shard transaction coordination.
 *
 * All-or-nothing begin and callback; commits run in begin order and a
 * mid-commit failure rolls back the remaining shards. This is not a
 * distributed two-phase commit — see docs/TRANSACTIONS.md for the
 * reconciliation patterns.
 */
class CrossShardTransactionCoordinator
{
    public function __construct(protected DatabaseManager $db)
    {
    }

    /**
     * Run a callback inside a transaction opened on every given shard.
     *
     * @param array<string> $shardConnections
     * @param Closure(): mixed $callback
     * @return mixed the callback result
     * @throws ShardingException when no shard is given, a shard cannot begin, or a commit fails
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
}
