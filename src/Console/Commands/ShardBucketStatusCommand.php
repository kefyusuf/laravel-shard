<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Console\Concerns\HandlesJsonOutput;
use Laravel\RedisShard\Support\ShardRegistry;

class ShardBucketStatusCommand extends Command
{
    use HandlesJsonOutput;
    use \Laravel\RedisShard\Console\Concerns\PresentsReports;

    protected $signature = 'shard:bucket-status
                            {--format=table : Output format (table, json)}';

    protected $description = 'Show the virtual bucket to shard assignment distribution';

    public function handle(): int
    {
        $bucketCount = app(\Laravel\RedisShard\Support\RedisShardConfig::class)->bucketCount();
        $map = ShardRegistry::readBucketMap();
        $shards = app(\Laravel\RedisShard\Support\RedisShardConfig::class)->shardNames();

        $perShard = array_fill_keys($shards, 0);
        $unassigned = 0;

        foreach ($map as $shard) {
            if (isset($perShard[$shard])) {
                $perShard[$shard]++;
            } else {
                $unassigned++;
            }
        }

        $unassigned += $bucketCount - count($map);

        $this->renderReport([
            'json' => [
                'summary' => [
                    'status' => 'ok',
                    'bucket_count' => $bucketCount,
                    'assigned' => count($map),
                    'unassigned' => $unassigned,
                ],
                'shards' => $perShard,
            ],
            'header' => ["Virtual buckets: {$bucketCount} (assigned: " . count($map) . ", unassigned: {$unassigned})"],
            'headers' => ['Shard', 'Buckets'],
            'rows' => array_map(static fn (string $shard, int $buckets): array => [$shard, $buckets], array_keys($perShard), $perShard),
            'meta' => ['Move a bucket with: php artisan shard:bucket {bucket} {shard}'],
        ]);

        return 0;
    }
}
