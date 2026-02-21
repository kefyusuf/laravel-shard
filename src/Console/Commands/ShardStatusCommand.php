<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;

class ShardStatusCommand extends Command
{
    /**
     * Whether command should print JSON only output.
     */
    protected bool $jsonOutput = false;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:status
                            {--table= : Show status for specific table}
                            {--shard= : Show status for specific shard}
                            {--format=table : Output format (table, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the current status of all shards';

    /**
     * Execute the console command.
     *
     * @param ShardLocatorInterface $locator
     * @return int
     */
    public function handle(ShardLocatorInterface $locator): int
    {
        $table = $this->option('table');
        $shard = $this->option('shard');
        $format = (string) $this->option('format');
        $this->jsonOutput = $format === 'json';
        $availableShards = ShardManager::getAvailableShards();

        if (empty($availableShards)) {
            if ($this->jsonOutput) {
                $this->line(json_encode([
                    'summary' => [
                        'status' => 'error',
                        'total_shards' => 0,
                    ],
                    'error' => 'No available shards found.',
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error('No available shards found.');
            }
            return 1;
        }

        if ($table) {
            return $this->showTableStatus($table, $locator, $format);
        }

        if ($shard) {
            return $this->showShardStatus($shard, $locator, $format);
        }

        return $this->showOverallStatus($locator, $format);
    }

    /**
     * Show overall shard status.
     *
     * @param ShardLocatorInterface $locator
     * @param string $format
     * @return int
     */
    protected function showOverallStatus(ShardLocatorInterface $locator, string $format): int
    {
        $shards = ShardManager::getAvailableShards();
        $metadata = ShardMetadata::all()->keyBy('name');
        
        $data = [];
        foreach ($shards as $shardName) {
            $meta = $metadata->get($shardName);
            $data[] = [
                'Shard' => $shardName,
                'Status' => $meta?->status ?? 'unknown',
                'Records' => $meta?->record_count ?? 0,
                'Last Rebalanced' => $meta?->last_rebalanced_at?->diffForHumans() ?? 'Never',
                'Created' => $meta?->created_at?->diffForHumans() ?? 'Unknown',
            ];
        }

        if ($format === 'json') {
            $this->line(json_encode(
                $this->buildOverallPayload($shards, $metadata),
                JSON_PRETTY_PRINT
            ));
        } else {
            $this->table(['Shard', 'Status', 'Records', 'Last Rebalanced', 'Created'], $data);
            $defaultStrategy = $this->resolveDefaultStrategyName();
            $this->info("\nTotal Shards: " . count($shards));
            $this->info("Active Strategies: " . ShardManager::strategies()->count());
            $this->info("Default Strategy: {$defaultStrategy}");
        }

        return 0;
    }

    /**
     * Show status for a specific table.
     *
     * @param string $table
     * @param ShardLocatorInterface $locator
     * @param string $format
     * @return int
     */
    protected function showTableStatus(string $table, ShardLocatorInterface $locator, string $format): int
    {
        $shards = ShardManager::getAvailableShards();
        $data = [];
        $totalKeys = 0;

        foreach ($shards as $shardName) {
            $keys = $locator->getKeysForShard($table, $shardName);
            $keyCount = count($keys);
            $totalKeys += $keyCount;
            
            $data[] = [
                'Shard' => $shardName,
                'Keys' => $keyCount,
                'Percentage' => '0%',
            ];
        }

        // Recalculate percentages now that we have total
        foreach ($data as &$row) {
            $keyCount = $row['Keys'];
            $row['Percentage'] = $totalKeys > 0 ? round(($keyCount / $totalKeys) * 100, 2) . '%' : '0%';
        }

        if ($format === 'json') {
            $this->line(json_encode([
                'summary' => $this->buildTableSummaryPayload($table, $totalKeys, $data),
                'shards' => $data,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info("Table: {$table}");
            $this->table(['Shard', 'Keys', 'Percentage'], $data);
            $this->info("Total Keys: {$totalKeys}");
        }

        return 0;
    }

    /**
     * Show status for a specific shard.
     *
     * @param string $shardName
     * @param ShardLocatorInterface $locator
     * @param string $format
     * @return int
     */
    protected function showShardStatus(string $shardName, ShardLocatorInterface $locator, string $format): int
    {
        $availableShards = ShardManager::getAvailableShards();
        
        if (!in_array($shardName, $availableShards)) {
            if ($format === 'json') {
                $this->line(json_encode([
                    'summary' => [
                        'status' => 'error',
                        'shard' => $shardName,
                    ],
                    'error' => "Shard '{$shardName}' not found.",
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error("Shard '{$shardName}' not found.");
            }
            return 1;
        }

        $metadata = ShardMetadata::where('name', $shardName)->first();
        
        // Get table distribution for this shard
        $tables = config('redis_sharding.monitored_tables', ['users', 'orders', 'products']);
        $tableData = [];
        
        foreach ($tables as $table) {
            $keys = $locator->getKeysForShard($table, $shardName);
            if (!empty($keys)) {
                $tableData[] = [
                    'Table' => $table,
                    'Keys' => count($keys),
                ];
            }
        }

        if ($format === 'json') {
            $this->line(json_encode([
                'summary' => $this->buildShardSummaryPayload($shardName, $metadata?->status, $metadata?->record_count, $tableData),
                'shard' => [
                    'name' => $shardName,
                    'status' => $metadata?->status ?? 'unknown',
                    'record_count' => $metadata?->record_count ?? 0,
                    'created_at' => $metadata?->created_at,
                    'last_rebalanced_at' => $metadata?->last_rebalanced_at,
                ],
                'tables' => $tableData,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info("Shard: {$shardName}");
            $this->info("Status: " . ($metadata?->status ?? 'unknown'));
            $this->info("Record Count: " . ($metadata?->record_count ?? 0));
            $this->info("Created: " . ($metadata?->created_at?->diffForHumans() ?? 'Unknown'));
            $this->info("Last Rebalanced: " . ($metadata?->last_rebalanced_at?->diffForHumans() ?? 'Never'));
            
            if (!empty($tableData)) {
                $this->line('');
                $this->info('Table Distribution:');
                $this->table(['Table', 'Keys'], $tableData);
            }
        }

        return 0;
    }

    /**
     * Build payload for overall status in JSON mode.
     */
    protected function buildOverallPayload(array $shards, \Illuminate\Support\Collection $metadata): array
    {
        $items = [];
        foreach ($shards as $shardName) {
            $meta = $metadata->get($shardName);
            $items[] = [
                'name' => $shardName,
                'status' => $meta?->status ?? 'unknown',
                'records' => $meta?->record_count ?? 0,
                'last_rebalanced' => $meta?->last_rebalanced_at?->diffForHumans() ?? 'Never',
                'created' => $meta?->created_at?->diffForHumans() ?? 'Unknown',
            ];
        }

        return [
            'summary' => [
                'status' => 'ok',
                'total_shards' => count($shards),
                'active_strategies' => ShardManager::strategies()->count(),
                'default_strategy' => $this->resolveDefaultStrategyName(),
            ],
            'shards' => $items,
        ];
    }

    /**
     * Build summary payload for table-level status in JSON mode.
     */
    protected function buildTableSummaryPayload(string $table, int $totalKeys, array $rows): array
    {
        return [
            'status' => 'ok',
            'table' => $table,
            'total_keys' => $totalKeys,
            'total_shards' => count($rows),
        ];
    }

    /**
     * Build summary payload for shard-level status in JSON mode.
     */
    protected function buildShardSummaryPayload(string $shardName, ?string $status, ?int $recordCount, array $tables): array
    {
        return [
            'status' => 'ok',
            'shard' => $shardName,
            'shard_status' => $status ?? 'unknown',
            'record_count' => $recordCount ?? 0,
            'table_count' => count($tables),
        ];
    }

    /**
     * Resolve default strategy name while staying resilient to invalid configuration.
     */
    protected function resolveDefaultStrategyName(): string
    {
        try {
            return ShardManager::strategy()->getName();
        } catch (\Throwable $e) {
            return 'N/A';
        }
    }
}
