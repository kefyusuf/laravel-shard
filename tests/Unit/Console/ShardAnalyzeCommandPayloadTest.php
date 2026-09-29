<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Commands\ShardAnalyzeCommand;
use Laravel\RedisShard\Tests\TestCase;

class ShardAnalyzeCommandPayloadTest extends TestCase
{
    public function test_build_overall_summary_selects_best_strategy(): void
    {
        $command = new class () extends ShardAnalyzeCommand {
            public function exposedBuildOverallSummary(array $results, int $sampleSize): array
            {
                return $this->buildOverallSummary($results, $sampleSize);
            }
        };

        $summary = $command->exposedBuildOverallSummary([
            'modulo' => ['balance_score' => 80.5],
            'consistent_hashing' => ['balance_score' => 92.1],
            'range_based' => ['balance_score' => 70.2],
        ], 1000);

        $this->assertSame(1000, $summary['sample_size']);
        $this->assertSame(3, $summary['strategy_count']);
        $this->assertSame('consistent_hashing', $summary['best_strategy']);
        $this->assertSame(92.1, $summary['best_balance_score']);
    }

    public function test_build_table_summary_includes_key_metrics(): void
    {
        $command = new class () extends ShardAnalyzeCommand {
            public function exposedBuildTableSummary(array $analysis): array
            {
                return $this->buildTableSummary($analysis);
            }
        };

        $summary = $command->exposedBuildTableSummary([
            'table' => 'users',
            'total_keys' => 150,
            'shard_count' => 3,
            'balance_score' => 88.4,
            'recommendations' => ['Rebalance shard_a', 'Monitor shard_b'],
        ]);

        $this->assertSame('users', $summary['table']);
        $this->assertSame(150, $summary['total_keys']);
        $this->assertSame(3, $summary['shard_count']);
        $this->assertSame(88.4, $summary['balance_score']);
        $this->assertSame(2, $summary['recommendation_count']);
    }
}
