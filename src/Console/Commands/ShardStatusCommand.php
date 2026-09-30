<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Console\Concerns\HandlesJsonOutput;
use Laravel\RedisShard\Console\Concerns\PresentsReports;
use Laravel\RedisShard\Console\Concerns\ValidatesOutputFormat;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;

class ShardStatusCommand extends Command
{
    use HandlesJsonOutput;
    use PresentsReports;
    use ValidatesOutputFormat;

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
        $format = $this->validateOutputFormat((string) $this->option('format'));
        if ($format === null) {
            return 1;
        }

        $this->jsonOutput = $format === 'json';
        $availableShards = ShardManager::getAvailableShards();

        if (empty($availableShards)) {
            if ($this->jsonOutput) {
                $this->emitJson(
                    $this->makeErrorPayload('No available shards found.', ['total_shards' => 0])
                );
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

        $rows = [];
        foreach ($shards as $shardName) {
            $meta = ShardMetadata::where('name', $shardName)->first();
            $rows[] = [
                'name' => $shardName,
                'status' => $meta->status ?? 'unknown',
                'records' => $meta->record_count ?? 0,
                'last_rebalanced' => $meta?->last_rebalanced_at?->diffForHumans() ?? 'Never',
                'created' => $meta?->created_at?->diffForHumans() ?? 'Unknown',
            ];
        }

        $this->renderReport([
            'json' => [
                'summary' => [
                    'status' => 'ok',
                    'total_shards' => count($shards),
                    'active_strategies' => ShardManager::strategies()->count(),
                    'default_strategy' => $this->resolveDefaultStrategyName(),
                ],
                'shards' => $rows,
            ],
            'headers' => ['Shard', 'Status', 'Records', 'Last Rebalanced', 'Created'],
            'rows' => array_map(static fn (array $row): array => [
                $row['name'],
                $row['status'],
                $row['records'],
                $row['last_rebalanced'],
                $row['created'],
            ], $rows),
            'meta' => [
                "\nTotal Shards: " . count($shards),
                'Active Strategies: ' . ShardManager::strategies()->count(),
                'Default Strategy: ' . $this->resolveDefaultStrategyName(),
            ],
        ]);

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

        $this->renderReport([
            'json' => [
                'summary' => [
                    'status' => 'ok',
                    'table' => $table,
                    'total_keys' => $totalKeys,
                    'total_shards' => count($data),
                ],
                'shards' => $data,
            ],
            'header' => ["Table: {$table}"],
            'headers' => ['Shard', 'Keys', 'Percentage'],
            'rows' => $data,
            'meta' => ["Total Keys: {$totalKeys}"],
        ]);

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

        if (! in_array($shardName, $availableShards)) {
            if ($format === 'json') {
                $this->emitJson(
                    $this->makeErrorPayload("Shard '{$shardName}' not found.", ['shard' => $shardName])
                );
            } else {
                $this->error("Shard '{$shardName}' not found.");
            }

            return 1;
        }

        $metadata = ShardMetadata::where('name', $shardName)->first();

        // Get table distribution for this shard
        $tables = app(\Laravel\RedisShard\Support\RedisShardConfig::class)->monitoredTables();
        $tableData = [];

        foreach ($tables as $table) {
            $keys = $locator->getKeysForShard($table, $shardName);
            if (! empty($keys)) {
                $tableData[] = [
                    'Table' => $table,
                    'Keys' => count($keys),
                ];
            }
        }

        $this->renderReport([
            'json' => [
                'summary' => [
                    'status' => 'ok',
                    'shard' => $shardName,
                    'shard_status' => $metadata->status ?? 'unknown',
                    'record_count' => $metadata->record_count ?? 0,
                    'table_count' => count($tableData),
                ],
                'shard' => [
                    'name' => $shardName,
                    'status' => $metadata->status ?? 'unknown',
                    'record_count' => $metadata->record_count ?? 0,
                    'created_at' => $metadata?->created_at,
                    'last_rebalanced_at' => $metadata?->last_rebalanced_at,
                ],
                'tables' => $tableData,
            ],
            'header' => [
                "Shard: {$shardName}",
                'Status: ' . ($metadata->status ?? 'unknown'),
                'Record Count: ' . ($metadata->record_count ?? 0),
                'Created: ' . ($metadata?->created_at?->diffForHumans() ?? 'Unknown'),
                'Last Rebalanced: ' . ($metadata?->last_rebalanced_at?->diffForHumans() ?? 'Never'),
                '',
                'Table Distribution:',
            ],
            'headers' => ['Table', 'Keys'],
            'rows' => $tableData,
        ]);

        return 0;
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
