<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;

class ShardCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:cleanup
                            {--dry-run : Show what would be cleaned without deleting}
                            {--table=* : Restrict cleanup to specific table names}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphaned shard keys and stale shard map entries in Redis';

    /**
     * Execute the console command.
     */
    public function handle(ShardLocatorInterface $locator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targetTables = collect((array) $this->option('table'))
            ->filter(fn ($table) => is_string($table) && $table !== '')
            ->values()
            ->all();

        $redisConnection = config('redis_sharding.redis_connection', 'default');
        $redis = app('redis')->connection($redisConnection);
        $shards = ShardManager::getAvailableShards();
        $shardLookup = array_fill_keys($shards, true);

        $this->info($dryRun ? 'Running cleanup in dry-run mode...' : 'Running cleanup...');

        $orphanedKeys = 0;
        $staleMapEntries = 0;
        $scannedKeys = 0;
        $tablesSeen = [];

        $keys = $redis->keys('shard:*');

        foreach ($keys as $key) {
            $parts = explode(':', (string) $key, 3);
            if (count($parts) !== 3) {
                continue;
            }

            [$prefix, $table, $recordKey] = $parts;
            if ($prefix !== 'shard') {
                continue;
            }

            if (!empty($targetTables) && !in_array($table, $targetTables, true)) {
                continue;
            }

            $tablesSeen[$table] = true;
            $scannedKeys++;

            $locatedShard = $locator->locate($table, $recordKey);
            if ($locatedShard === null || !isset($shardLookup[$locatedShard])) {
                $orphanedKeys++;

                if (!$dryRun) {
                    $redis->del($key);
                }
            }
        }

        $tablesToClean = !empty($targetTables) ? $targetTables : array_keys($tablesSeen);

        foreach ($tablesToClean as $table) {
            foreach ($shards as $shard) {
                $shardMapKey = "shard_map:{$table}:{$shard}";
                $keysForShard = $redis->smembers($shardMapKey);

                foreach ($keysForShard as $recordKey) {
                    $locatedShard = $locator->locate($table, $recordKey);

                    if ($locatedShard !== $shard) {
                        $staleMapEntries++;

                        if (!$dryRun) {
                            $redis->srem($shardMapKey, (string) $recordKey);
                        }
                    }
                }
            }
        }

        $this->line("Scanned shard keys: {$scannedKeys}");
        $this->line("Orphaned keys " . ($dryRun ? 'found' : 'cleaned') . ": {$orphanedKeys}");
        $this->line("Stale shard-map entries " . ($dryRun ? 'found' : 'cleaned') . ": {$staleMapEntries}");

        return 0;
    }
}
