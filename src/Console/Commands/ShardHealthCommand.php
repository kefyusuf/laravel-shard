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
     * @var bool
     */
    protected bool $jsonOutput = false;

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
        $this->jsonOutput = $format === 'json';
        
        if (!$this->jsonOutput) {
            $this->info('Checking shard health...');
        }
        
        $issues = [];
        $issues = array_merge($issues, $this->checkShardConnectivity());
        $redisIssues = $this->checkRedisConnectivity();
        $issues = array_merge($issues, $redisIssues);

        if (empty($redisIssues)) {
            $issues = array_merge($issues, $this->checkShardBalance($locator));
            $issues = array_merge($issues, $this->checkOrphanedKeys($locator));
        } else {
            if (!$this->jsonOutput) {
                $this->warn('Skipping shard balance/orphan checks because Redis is unavailable.');
            }
        }

        $summary = $this->buildSummary($issues);
        
        if (empty($issues)) {
            if ($format === 'json') {
                $this->line(json_encode([
                    'summary' => $summary,
                    'issues' => [],
                ], JSON_PRETTY_PRINT));
            } else {
                $this->info('✅ All shards are healthy!');
            }
            return 0;
        }

        if ($fix) {
            $fixResult = $this->fixIssues($issues, $locator);

            if ($format === 'json') {
                $this->line(json_encode([
                    'summary' => $summary,
                    'issues' => $issues,
                    'fix' => $fixResult,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->displayIssues($issues);
            }

            return (int) $fixResult['exit_code'];
        }

        if ($format === 'json') {
            $this->line(json_encode([
                'summary' => $summary,
                'issues' => $issues,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->displayIssues($issues);
        }

        $this->warn('Run with --fix to attempt automatic repairs.');
        return 1;
    }

    /**
     * Build a summary payload for the health report.
     *
     * @param array<int, array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    protected function buildSummary(array $issues): array
    {
        $severityCounts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        $fixableCount = 0;

        foreach ($issues as $issue) {
            $severity = strtolower((string) ($issue['severity'] ?? ''));
            if (array_key_exists($severity, $severityCounts)) {
                $severityCounts[$severity]++;
            }

            if (($issue['fixable'] ?? false) === true) {
                $fixableCount++;
            }
        }

        return [
            'healthy' => empty($issues),
            'total_issues' => count($issues),
            'fixable_issues' => $fixableCount,
            'severity' => $severityCounts,
        ];
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
                if (!$this->jsonOutput) {
                    $this->line("✅ Shard '{$shardName}' is accessible");
                }
            } catch (\Exception $e) {
                $issues[] = [
                    'type' => 'connectivity',
                    'severity' => 'critical',
                    'shard' => $shardName,
                    'message' => "Cannot connect to shard '{$shardName}': " . $e->getMessage(),
                    'fixable' => false,
                ];
                if (!$this->jsonOutput) {
                    $this->line("❌ Shard '{$shardName}' is not accessible");
                }
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
            $redisConnection = config('redis_sharding.redis_connection', 'default');
            $redis = app('redis')->connection($redisConnection);
            $redis->ping();
            if (!$this->jsonOutput) {
                $this->line('✅ Redis is accessible');
            }
        } catch (\Exception $e) {
            $issues[] = [
                'type' => 'redis',
                'severity' => 'critical',
                'message' => 'Cannot connect to Redis: ' . $e->getMessage(),
                'fixable' => false,
            ];
            if (!$this->jsonOutput) {
                $this->line('❌ Redis is not accessible');
            }
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
        $tables = config('redis_sharding.monitored_tables', ['users', 'orders', 'products']);
        
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
        $shardLookup = array_fill_keys(ShardManager::getAvailableShards(), true);
        
        try {
            $redisConnection = config('redis_sharding.redis_connection', 'default');
            $redis = app('redis')->connection($redisConnection);
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
                if ($shardConnection === null || !isset($shardLookup[$shardConnection])) {
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
    protected function fixIssues(array $issues, ShardLocatorInterface $locator): array
    {
        $fixed = 0;
        $failed = 0;
        $unresolved = 0;
        
        foreach ($issues as $issue) {
            if (!$issue['fixable']) {
                $unresolved++;
                continue;
            }
            
            $this->info("Attempting to fix: {$issue['message']}");
            
            try {
                switch ($issue['type']) {
                    case 'imbalance':
                        if (isset($issue['fix_command'])) {
                            $parts = explode(' ', (string) $issue['fix_command'], 2);
                            $commandName = $parts[0] ?? '';
                            $table = $parts[1] ?? null;

                            if ($commandName !== '') {
                                $this->call($commandName, [
                                    'table' => $table,
                                ]);
                            }
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
                $unresolved++;
            }
        }
        
        if (!$this->jsonOutput) {
            $this->info("Fixed {$fixed} issue(s), {$failed} failed, {$unresolved} unresolved.");
        }
        return [
            'fixed' => $fixed,
            'failed' => $failed,
            'unresolved' => $unresolved,
            'exit_code' => $unresolved > 0 ? 1 : 0,
        ];
    }

}
