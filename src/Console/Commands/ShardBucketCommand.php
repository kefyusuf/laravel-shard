<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Support\ShardRegistry;

class ShardBucketCommand extends Command
{
    protected $signature = 'shard:bucket
                            {bucket : Virtual bucket number}
                            {shard : Target shard connection}';

    protected $description = 'Assign a virtual bucket to a shard (run shard:rebalance afterwards to move data)';

    public function handle(): int
    {
        $bucketCount = app(\Laravel\RedisShard\Support\RedisShardConfig::class)->bucketCount();
        $bucket = (int) $this->argument('bucket');
        $shard = (string) $this->argument('shard');

        if ($bucket < 0 || $bucket >= $bucketCount) {
            $this->error("Bucket must be between 0 and " . ($bucketCount - 1) . ".");

            return 1;
        }

        $shards = app(\Laravel\RedisShard\Support\RedisShardConfig::class)->shardNames();

        if (! in_array($shard, $shards, true)) {
            $this->error("Unknown shard connection \"{$shard}\". Available: " . implode(', ', $shards) . '.');

            return 1;
        }

        $previous = null;

        // The read-modify-write runs under the registry's exclusive lock, so
        // concurrent shard:bucket runs cannot clobber each other's assignments.
        ShardRegistry::mutateBucketMap(function (array $map) use ($bucket, $shard, &$previous): array {
            $previous = $map[$bucket] ?? null;
            $map[$bucket] = $shard;

            return $map;
        });

        $from = $previous !== null ? " (was {$previous})" : '';
        $this->info("Bucket {$bucket} assigned to {$shard}{$from}. Run php artisan shard:rebalance to move its data.");

        return 0;
    }
}
