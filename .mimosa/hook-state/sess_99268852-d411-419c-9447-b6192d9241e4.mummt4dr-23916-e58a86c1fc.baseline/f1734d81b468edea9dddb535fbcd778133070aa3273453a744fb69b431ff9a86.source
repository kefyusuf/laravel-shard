<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Commands\ShardAnalyzeCommand;
use Laravel\RedisShard\Strategies\ModuloStrategy;
use Laravel\RedisShard\Tests\TestCase;

class ShardAnalyzeCommandDistributionTest extends TestCase
{
    public function test_distribution_uses_all_samples_for_odd_sample_size(): void
    {
        $command = new class extends ShardAnalyzeCommand {
            public function exposedTestDistribution($strategy, array $shards, int $sampleSize): array
            {
                return $this->testDistribution($strategy, $shards, $sampleSize);
            }
        };

        $distribution = $command->exposedTestDistribution(
            new ModuloStrategy(),
            ['shard1', 'shard2', 'shard3'],
            7
        );

        $this->assertSame(7, array_sum($distribution));
    }
}
