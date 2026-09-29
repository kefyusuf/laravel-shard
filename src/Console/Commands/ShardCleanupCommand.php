<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\RedisShard\Console\Concerns\HandlesJsonOutput;
use Laravel\RedisShard\Console\Concerns\ScansRedisKeys;
use Laravel\RedisShard\Console\Concerns\ValidatesOutputFormat;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;

class ShardCleanupCommand extends Command
{
    use HandlesJsonOutput;
    use ScansRedisKeys;
    use ValidatesOutputFormat;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:cleanup
                            {--dry-run : Show what would be cleaned without deleting}
                            {--table=* : Restrict cleanup to specific table names}
                            {--format=table : Output format (table, json)}';

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
        $format = $this->validateOutputFormat((string) $this->option('format'));
        if ($format === null) {
            return 1;
        }

        $targetTables = collect((array) $this->option('table'))
            ->filter(fn ($table) => is_string($table) && $table !== '')
            ->values()
            ->all();

        $redisConnection = config('redis_sharding.redis_connection', 'default');
        $redis = app(RedisFactory::class)->connection($redisConnection);
        $shards = ShardManager::getAvailableShards();
        $shardLookup = array_fill_keys($shards, true);

        if ($format !== 'json') {
            $this->info($dryRun ? 'Running cleanup in dry-run mode...' : 'Running cleanup...');
        }

        $orphanedKeys = 0;
        $staleMapEntries = 0;
        $scannedKeys = 0;
        $tablesSeen = [];

        foreach ($this->scanKeys($redis, 'shard:*') as $key) {
            $parsed = $this->parseShardRedisKey((string) $key);
            if ($parsed === null) {
                continue;
            }

            [$table, $recordKey] = $parsed;

            if (! empty($targetTables) && ! in_array($table, $targetTables, true)) {
                continue;
            }

            $tablesSeen[$table] = true;
            $scannedKeys++;

            $locatedShard = $locator->locate($table, $recordKey);
            if ($locatedShard === null || ! isset($shardLookup[$locatedShard])) {
                $orphanedKeys++;

                if (! $dryRun) {
                    $redis->command('del', ["shard:{$table}:{$recordKey}"]);
                }
            }
        }

        $tablesToClean = ! empty($targetTables)
            ? $targetTables
            : (! empty($tablesSeen) ? array_keys($tablesSeen) : (array) config('redis_sharding.monitored_tables', []));

        $tablesToClean = array_values(array_filter(
            array_unique($tablesToClean),
            fn ($table): bool => is_string($table) && $table !== ''
        ));

        foreach ($tablesToClean as $table) {
            foreach ($shards as $shard) {
                $shardMapKey = "shard_map:{$table}:{$shard}";
                $keysForShard = $redis->command('smembers', [$shardMapKey]);

                foreach ($keysForShard as $recordKey) {
                    $locatedShard = $locator->locate($table, $recordKey);

                    if ($locatedShard !== $shard) {
                        $staleMapEntries++;

                        if (! $dryRun) {
                            $redis->command('srem', [$shardMapKey, (string) $recordKey]);
                        }
                    }
                }
            }
        }

        if ($format === 'json') {
            $this->emitJson(
                $this->buildReportPayload($dryRun, $scannedKeys, $orphanedKeys, $staleMapEntries)
            );
        } else {
            $this->line("Scanned shard keys: {$scannedKeys}");
            $this->line("Orphaned keys " . ($dryRun ? 'found' : 'cleaned') . ": {$orphanedKeys}");
            $this->line("Stale shard-map entries " . ($dryRun ? 'found' : 'cleaned') . ": {$staleMapEntries}");
        }

        return 0;
    }

    /**
     * @return array<string, array<string, bool|int|string>>
     */
    protected function buildReportPayload(
        bool $dryRun,
        int $scannedKeys,
        int $orphanedKeys,
        int $staleMapEntries
    ): array {
        return [
            'summary' => [
                'status' => 'ok',
                'dry_run' => $dryRun,
                'scanned_keys' => $scannedKeys,
                'orphaned_keys' => $orphanedKeys,
                'stale_map_entries' => $staleMapEntries,
            ],
            'report' => [
                'dry_run' => $dryRun,
                'scanned_keys' => $scannedKeys,
                'orphaned_keys' => $orphanedKeys,
                'stale_map_entries' => $staleMapEntries,
            ],
        ];
    }

    /**
     * @return array{0:string,1:string}|null
     */
    protected function parseShardRedisKey(string $rawKey): ?array
    {
        $markerPosition = strpos($rawKey, 'shard:');
        if ($markerPosition === false) {
            return null;
        }

        $normalizedKey = substr($rawKey, $markerPosition);
        $parts = explode(':', $normalizedKey, 3);
        if (count($parts) !== 3) {
            return null;
        }

        return [(string) $parts[1], (string) $parts[2]];
    }
}
