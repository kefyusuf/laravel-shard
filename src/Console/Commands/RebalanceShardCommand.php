<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Console\Concerns\HandlesJsonOutput;
use Laravel\RedisShard\Console\Concerns\ValidatesOutputFormat;
use Laravel\RedisShard\Contracts\RebalanceDataMoverInterface;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;

class RebalanceShardCommand extends Command
{
    use HandlesJsonOutput;
    use ValidatesOutputFormat;

    /**
     * @var bool
     */
    protected bool $jsonOutput = false;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:rebalance
                             {table : The table to rebalance}
                             {--dry-run : Run without making changes}
                             {--force : Skip confirmation prompt}
                             {--metadata-only : Update only shard mappings, do not move table data}
                             {--format=table : Output format (table, json)}
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
        $force = $this->option('force');
        $metadataOnly = $this->option('metadata-only');
        $format = $this->validateOutputFormat((string) $this->option('format'));
        if ($format === null) {
            return 1;
        }

        $strategyName = $this->option('strategy');
        $this->jsonOutput = $format === 'json';

        if (!$this->jsonOutput) {
            $this->info("Rebalancing table: {$table}");
        }
        if ($dryRun && !$this->jsonOutput) {
            $this->warn('Dry run mode - no changes will be made');
        }

        /** @var RebalanceDataMoverInterface|null $dataMover */
        $dataMover = app()->bound(RebalanceDataMoverInterface::class)
            ? app()->make(RebalanceDataMoverInterface::class)
            : null;

        if (!$dryRun && !$metadataOnly && $dataMover === null) {
            return $this->respond(1, $this->buildErrorPayload(
                $table,
                $dryRun,
                $metadataOnly,
                'No data mover is registered for rebalance. Bind ' . RebalanceDataMoverInterface::class . ' or rerun with --metadata-only.'
            ));
        }

        // Get all available shards
        $availableShards = ShardManager::getAvailableShards();
        if (empty($availableShards)) {
            return $this->respond(1, $this->buildErrorPayload(
                $table,
                $dryRun,
                $metadataOnly,
                'No available shards found'
            ));
        }

        // Get the strategy to use
        try {
            $strategy = $strategyName ? 
                ShardManager::strategy($strategyName) : 
                ShardManager::strategy();
        } catch (\Exception $e) {
            return $this->respond(1, $this->buildErrorPayload(
                $table,
                $dryRun,
                $metadataOnly,
                $e->getMessage()
            ));
        }

        if (!$this->jsonOutput) {
            $this->info("Using strategy: {$strategy->getName()}");
        }

        // Get all keys for each shard
        $shardKeys = [];
        $totalKeys = 0;

        foreach ($availableShards as $shard) {
            $keys = $locator->getKeysForShard($table, $shard);
            $shardKeys[$shard] = $keys;
            $totalKeys += count($keys);
            
            if (!$this->jsonOutput) {
                $this->info("Shard {$shard}: " . count($keys) . " keys");
            }
        }

        if ($totalKeys === 0) {
            return $this->respond(0, [
                'summary' => [
                    'table' => $table,
                    'status' => 'no_keys',
                    'dry_run' => $dryRun,
                    'metadata_only' => $metadataOnly,
                    'strategy' => $strategy->getName(),
                    'total_keys' => 0,
                    'total_moves' => 0,
                ],
            ]);
        }

        // Calculate moves needed
        $moves = [];
        $idealCount = ceil($totalKeys / count($availableShards));
        
        if (!$this->jsonOutput) {
            $this->info("Ideal count per shard: {$idealCount}");
        }

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

        if (!$this->jsonOutput) {
            $this->info("Total moves needed: " . count($moves));
        }

        if (empty($moves)) {
            return $this->respond(0, [
                'summary' => [
                    'table' => $table,
                    'status' => 'no_rebalance_needed',
                    'dry_run' => $dryRun,
                    'metadata_only' => $metadataOnly,
                    'strategy' => $strategy->getName(),
                    'total_keys' => $totalKeys,
                    'total_moves' => 0,
                ],
            ]);
        }

        if ($dryRun) {
            return $this->respond(0, $this->buildDryRunPayload(
                $table,
                $metadataOnly,
                $strategy->getName(),
                $totalKeys,
                $moves
            ));
        }

        // Confirm before proceeding
        if (!$force && !$this->confirm('Do you wish to proceed with rebalancing?')) {
            return $this->respond(0, [
                'summary' => [
                    'table' => $table,
                    'status' => 'cancelled',
                    'dry_run' => false,
                    'metadata_only' => $metadataOnly,
                    'strategy' => $strategy->getName(),
                    'total_keys' => $totalKeys,
                    'total_moves' => count($moves),
                ],
            ]);
        }

        // Perform the moves
        $bar = null;
        if (!$this->jsonOutput) {
            $bar = $this->output->createProgressBar(count($moves));
            $bar->start();
        }

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
                if (!$this->jsonOutput) {
                    $this->newLine();
                    $this->error("Error moving key {$move['key']}: {$e->getMessage()}");
                }
                $errorCount++;
            }
            
            if ($bar !== null) {
                $bar->advance();
            }
        }

        if ($bar !== null) {
            $bar->finish();
            $this->newLine(2);
        }

        // Update metadata
        foreach ($availableShards as $shard) {
            $metadata = ShardMetadata::where('connection', $shard)->first();
            
            if ($metadata) {
                $keys = $locator->getKeysForShard($table, $shard);
                $metadata->updateRecordCount(count($keys));
                $metadata->markRebalanced();
            }
        }

        $exitCode = $errorCount > 0 ? 1 : 0;

        return $this->respond($exitCode, [
            'summary' => [
                'table' => $table,
                'status' => $exitCode === 0 ? 'completed' : 'completed_with_errors',
                'dry_run' => false,
                'metadata_only' => $metadataOnly,
                'strategy' => $strategy->getName(),
                'total_keys' => $totalKeys,
                'total_moves' => count($moves),
                'successful_moves' => $successCount,
                'failed_moves' => $errorCount,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function respond(int $exitCode, array $payload): int
    {
        if ($this->jsonOutput) {
            $this->emitJson($payload);
        } elseif (isset($payload['error']) && is_string($payload['error']) && $payload['error'] !== '') {
            $this->error($payload['error']);
        } elseif (isset($payload['summary']['status']) && is_string($payload['summary']['status'])) {
            $status = (string) $payload['summary']['status'];
            if ($status === 'completed' || $status === 'completed_with_errors') {
                $successful = (int) ($payload['summary']['successful_moves'] ?? 0);
                $failed = (int) ($payload['summary']['failed_moves'] ?? 0);
                $this->info("Rebalancing complete: {$successful} successful, {$failed} failed");
            } elseif ($status === 'dry_run') {
                $moves = (int) ($payload['summary']['total_moves'] ?? 0);
                $this->info("Dry run complete. Planned moves: {$moves}");
            } elseif ($status === 'no_rebalance_needed') {
                $this->info('No rebalancing needed');
            } elseif ($status === 'no_keys') {
                $this->info('No keys found to rebalance');
            } elseif ($status === 'cancelled') {
                $this->warn('Rebalance cancelled by user.');
            }
        }

        return $exitCode;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildErrorPayload(string $table, bool $dryRun, bool $metadataOnly, string $error): array
    {
        return $this->makeErrorPayload($error, [
            'table' => $table,
            'dry_run' => $dryRun,
            'metadata_only' => $metadataOnly,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $moves
     * @return array<string, mixed>
     */
    protected function buildDryRunPayload(
        string $table,
        bool $metadataOnly,
        string $strategy,
        int $totalKeys,
        array $moves
    ): array {
        return [
            'summary' => [
                'table' => $table,
                'status' => 'dry_run',
                'dry_run' => true,
                'metadata_only' => $metadataOnly,
                'strategy' => $strategy,
                'total_keys' => $totalKeys,
                'total_moves' => count($moves),
            ],
            'moves' => $moves,
        ];
    }
}
