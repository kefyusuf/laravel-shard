<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;

class ShardHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:health
                            {--fix : Attempt to fix detected issues}
                            {--format=table : Output format (table, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the health of all shards and detect issues';

    /**
     * Execute the console command.
     *
     * @param ShardLocatorInterface $locator
     * @return int
     */
    public function handle(ShardLocatorInterface $locator): int
    {
        $fix = $this->option('fix');
        $format = $this->option('format');
        
        $this->info('Checking shard health...');
        
        $issues = [];
        $issues = array_merge($issues, $this->checkShardConnectivity());
        $issues = array_merge($issues, $this->checkRedisConnectivity());
        $issues = array_merge($issues, $this->checkShardBalance($locator));
        $issues = array_merge($issues, $this->checkOrphanedKeys($locator));
        
        if (empty($issues)) {
            $this->info('✅ All shards are healthy!');
            return 0;
        }
        
        if ($format === 'json') {
            $this->line(json_encode(['issues' => $issues], JSON_PRETTY_PRINT));
        } else {
            $this->displayIssues($issues);
        }
        
        if ($fix) {
            return $this->fixIssues($issues, $locator);
        }
        
        $this->warn('Run with --fix to attempt automatic repairs.');
        return 1;
    }

    /**
     * Check if all shards are accessible.
     *
     * @return array
     */
    protected function checkShardConnectivity(): array
    {
        $issues = [];
        $shards = ShardManager::getAvailableShards();
        
        foreach ($shards as $shardName) {
            try {
                DB::connection($shardName)->getPdo();
                $this->line("✅ Shard '{$shardName}' is accessible");
            } catch (\Exception $e) {
                $issues[] = [
                    'type' => 'connectivity',
                    'severity' => 'critical',
                    'shard' => $shardName,
                    'message' => "Cannot connect to shard '{$shardName}': " . $e->getMessage(),
                    'fixable' => false,
                ];
                $this->line("❌ Shard '{$shardName}' is not accessible");
            }
        }
        
        return $issues;
    }

    /**
     * Check Redis connectivity.
     *
     * @return array
     */
    protected function checkRedisConnectivity(): array
    {
        $issues = [];
        
        try {
            $redis = app('redis');
            $redis->ping();
            $this->line('✅ Redis is accessible');
        } catch (\Exception $e) {
            $issues[] = [
                'type' => 'redis',
                'severity' => 'critical',
                'message' => 'Cannot connect to Redis: ' . $e->getMessage(),
                'fixable' => false,
            ];
            $this->line('❌ Redis is not accessible');
        }
        
        return $issues;
    }

    /**
     * Check shard balance.
     *
     * @param ShardLocatorInterface $locator
     * @return array
     */
    protected function checkShardBalance(ShardLocatorInterface $locator): array
    {
        $issues = [];
        $shards = ShardManager::getAvailableShards();
        $tables = ['users', 'orders', 'products']; // Make this configurable
        
        foreach ($tables as $table) {
            $distribution = [];
            $totalKeys = 0;
            
            foreach ($shards as $shardName) {
                $keyCount = count($locator->getKeysForShard($table, $shardName));
                $distribution[$shardName] = $keyCount;
                $totalKeys += $keyCount;
            }
            
            if ($totalKeys === 0) {
                continue;
            }
            
            $idealCount = $totalKeys / count($shards);
            $threshold = $idealCount * 0.3; // 30% deviation threshold
            
            foreach ($distribution as $shardName => $keyCount) {
                $deviation = abs($keyCount - $idealCount);
                if ($deviation > $threshold) {
                    $percentage = round(($deviation / $idealCount) * 100, 2);
                    $issues[] = [
                        'type' => 'imbalance',
                        'severity' => $percentage > 50 ? 'high' : 'medium',
                        'table' => $table,
                        'shard' => $shardName,
                        'message' => "Table '{$table}' on shard '{$shardName}' has {$keyCount} keys (deviation: {$percentage}%)",
                        'fixable' => true,
                        'fix_command' => "shard:rebalance {$table}",
                    ];
                }
            }
        }
        
        return $issues;
    }

    /**
     * Check for orphaned keys in Redis.
     *
     * @param ShardLocatorInterface $locator
     * @return array
     */
    protected function checkOrphanedKeys(ShardLocatorInterface $locator): array
    {
        $issues = [];
        
        try {
            $redis = app('redis');
            $keys = $redis->keys('shard:*');
            
            $orphanedCount = 0;
            foreach ($keys as $key) {
                // Extract table and key from Redis key format: shard:table:key
                $parts = explode(':', $key, 3);
                if (count($parts) !== 3) {
                    continue;
                }
                
                $table = $parts[1];
                $recordKey = $parts[2];
                
                $shardConnection = $locator->locate($table, $recordKey);
                if ($shardConnection === null) {
                    $orphanedCount++;
                }
            }
            
            if ($orphanedCount > 0) {
                $issues[] = [
                    'type' => 'orphaned_keys',
                    'severity' => 'medium',
                    'message' => "Found {$orphanedCount} orphaned keys in Redis",
                    'fixable' => true,
                    'fix_command' => 'shard:cleanup',
                ];
            }
        } catch (\Exception $e) {
            // Redis connectivity already checked above
        }
        
        return $issues;
    }

    /**
     * Display issues in a formatted table.
     *
     * @param array $issues
     * @return void
     */
    protected function displayIssues(array $issues): void
    {
        $this->error('🚨 Health check found ' . count($issues) . ' issue(s):');
        
        $tableData = [];
        foreach ($issues as $issue) {
            $tableData[] = [
                'Severity' => strtoupper($issue['severity']),
                'Type' => ucfirst($issue['type']),
                'Shard' => $issue['shard'] ?? 'N/A',
                'Message' => $issue['message'],
                'Fixable' => $issue['fixable'] ? '✅' : '❌',
            ];
        }
        
        $this->table(['Severity', 'Type', 'Shard', 'Message', 'Fixable'], $tableData);
    }

    /**
     * Attempt to fix detected issues.
     *
     * @param array $issues
     * @param ShardLocatorInterface $locator
     * @return int
     */
    protected function fixIssues(array $issues, ShardLocatorInterface $locator): int
    {
        $fixed = 0;
        $failed = 0;
        
        foreach ($issues as $issue) {
            if (!$issue['fixable']) {
                continue;
            }
            
            $this->info("Attempting to fix: {$issue['message']}");
            
            try {
                switch ($issue['type']) {
                    case 'imbalance':
                        if (isset($issue['fix_command'])) {
                            $this->call(explode(' ', $issue['fix_command'])[0], [
                                'table' => explode(' ', $issue['fix_command'])[1] ?? null
                            ]);
                            $fixed++;
                        }
                        break;
                        
                    case 'orphaned_keys':
                        $this->call('shard:cleanup');
                        $fixed++;
                        break;
                }
            } catch (\Exception $e) {
                $this->error("Failed to fix issue: {$e->getMessage()}");
                $failed++;
            }
        }
        
        $this->info("Fixed {$fixed} issue(s), {$failed} failed.");
        return $failed > 0 ? 1 : 0;
    }

}
