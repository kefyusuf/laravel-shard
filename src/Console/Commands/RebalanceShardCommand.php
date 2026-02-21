<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;

class RebalanceShardCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:rebalance
                             {table : The table to rebalance}
                             {--dry-run : Run without making changes}
                             {--metadata-only : Update only shard mappings, do not move table data}
                             {--strategy= : The sharding strategy to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebalance data across shards';

    /**
     * Execute the console command.
     *
     * @param ShardLocatorInterface $locator
     * @return int
     */
    public function handle(ShardLocatorInterface $locator): int
    {
        $table = $this->argument('table');
        $dryRun = $this->option('dry-run');
        $metadataOnly = $this->option('metadata-only');
        $strategyName = $this->option('strategy');

        $this->info("Rebalancing table: {$table}");
        if ($dryRun) {
            $this->warn('Dry run mode - no changes will be made');
        }

        /** @var RebalanceDataMoverInterface|null $dataMover */
        $dataMover = app()->bound(RebalanceDataMoverInterface::class)
            ? app()->make(RebalanceDataMoverInterface::class)
            : null;

        if (!$dryRun && !$metadataOnly && $dataMover === null) {
            $this->error('No data mover is registered for rebalance.');
            $this->line('Bind ' . RebalanceDataMoverInterface::class . ' or rerun with --metadata-only.');
            return 1;
        }

        // Get all available shards
        $availableShards = ShardManager::getAvailableShards();
        if (empty($availableShards)) {
            $this->error('No available shards found');
            return 1;
        }

        // Get the strategy to use
        try {
            $strategy = $strategyName ? 
                ShardManager::strategy($strategyName) : 
                ShardManager::strategy();
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info("Using strategy: {$strategy->getName()}");

        // Get all keys for each shard
        $shardKeys = [];
        $totalKeys = 0;

        foreach ($availableShards as $shard) {
            $keys = $locator->getKeysForShard($table, $shard);
            $shardKeys[$shard] = $keys;
            $totalKeys += count($keys);
            
            $this->info("Shard {$shard}: " . count($keys) . " keys");
        }

        if ($totalKeys === 0) {
            $this->info('No keys found to rebalance');
            return 0;
        }

        // Calculate moves needed
        $moves = [];
        $idealCount = ceil($totalKeys / count($availableShards));
        
        $this->info("Ideal count per shard: {$idealCount}");

        foreach ($shardKeys as $shard => $keys) {
            foreach ($keys as $key) {
                $targetShard = $strategy->determine($table, $key, $availableShards);
                
                if ($targetShard !== $shard) {
                    $moves[] = [
                        'key' => $key,
                        'from' => $shard,
                        'to' => $targetShard,
                    ];
                }
            }
        }

        $this->info("Total moves needed: " . count($moves));

        if (empty($moves)) {
            $this->info('No rebalancing needed');
            return 0;
        }

        if ($dryRun) {
            return 0;
        }

        // Confirm before proceeding
        if (!$this->confirm('Do you wish to proceed with rebalancing?')) {
            return 0;
        }

        // Perform the moves
        $bar = $this->output->createProgressBar(count($moves));
        $bar->start();

        $successCount = 0;
        $errorCount = 0;

        foreach ($moves as $move) {
            try {
                if (!$metadataOnly && $dataMover !== null) {
                    $moved = $dataMover->move($table, $move['key'], $move['from'], $move['to']);
                    if (!$moved) {
                        throw new \RuntimeException("Data move failed for key {$move['key']}");
                    }
                }

                // Update the shard location in Redis
                $locator->forget($table, $move['key']);
                $locator->register($table, $move['key'], $move['to']);

                $successCount++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error moving key {$move['key']}: {$e->getMessage()}");
                $errorCount++;
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Update metadata
        foreach ($availableShards as $shard) {
            $metadata = ShardMetadata::where('connection', $shard)->first();
            
            if ($metadata) {
                $keys = $locator->getKeysForShard($table, $shard);
                $metadata->updateRecordCount(count($keys));
                $metadata->markRebalanced();
            }
        }

        $this->info("Rebalancing complete: {$successCount} successful, {$errorCount} failed");
        
        return $errorCount > 0 ? 1 : 0;
    }
}
