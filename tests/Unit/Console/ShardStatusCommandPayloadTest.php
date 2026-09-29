<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Illuminate\Support\Collection;
use Laravel\RedisShard\Console\Commands\ShardStatusCommand;
use Laravel\RedisShard\Tests\TestCase;

class ShardStatusCommandPayloadTest extends TestCase
{
    public function test_build_error_payload_sets_error_status_and_message(): void
    {
        $command = new class () extends ShardStatusCommand {
            public function exposedBuildErrorPayload(string $error, array $summary = []): array
            {
                return $this->buildErrorPayload($error, $summary);
            }
        };

        $payload = $command->exposedBuildErrorPayload('No available shards found.');

        $this->assertSame('error', $payload['summary']['status']);
        $this->assertSame('No available shards found.', $payload['error']);
    }

    public function test_build_error_payload_preserves_context_fields(): void
    {
        $command = new class () extends ShardStatusCommand {
            public function exposedBuildErrorPayload(string $error, array $summary = []): array
            {
                return $this->buildErrorPayload($error, $summary);
            }
        };

        $payload = $command->exposedBuildErrorPayload('Shard not found.', ['shard' => 'shard9']);

        $this->assertSame('error', $payload['summary']['status']);
        $this->assertSame('shard9', $payload['summary']['shard']);
        $this->assertSame('Shard not found.', $payload['error']);
    }

    public function test_build_table_summary_payload_has_expected_fields(): void
    {
        $command = new class () extends ShardStatusCommand {
            public function exposedBuildTableSummaryPayload(string $table, int $totalKeys, array $rows): array
            {
                return $this->buildTableSummaryPayload($table, $totalKeys, $rows);
            }
        };

        $summary = $command->exposedBuildTableSummaryPayload('users', 5, [
            ['Shard' => 'shard1'],
            ['Shard' => 'shard2'],
        ]);

        $this->assertSame('ok', $summary['status']);
        $this->assertSame('users', $summary['table']);
        $this->assertSame(5, $summary['total_keys']);
        $this->assertSame(2, $summary['total_shards']);
    }

    public function test_build_shard_summary_payload_has_expected_fields(): void
    {
        $command = new class () extends ShardStatusCommand {
            public function exposedBuildShardSummaryPayload(
                string $shardName,
                ?string $status,
                ?int $recordCount,
                array $tables
            ): array {
                return $this->buildShardSummaryPayload($shardName, $status, $recordCount, $tables);
            }
        };

        $summary = $command->exposedBuildShardSummaryPayload('shard1', 'active', 12, [
            ['Table' => 'users', 'Keys' => 10],
            ['Table' => 'orders', 'Keys' => 2],
        ]);

        $this->assertSame('ok', $summary['status']);
        $this->assertSame('shard1', $summary['shard']);
        $this->assertSame('active', $summary['shard_status']);
        $this->assertSame(12, $summary['record_count']);
        $this->assertSame(2, $summary['table_count']);
    }

    public function test_build_overall_payload_embeds_summary_and_shards(): void
    {
        $command = new class () extends ShardStatusCommand {
            public function exposedBuildOverallPayload(array $shards, Collection $metadata): array
            {
                return $this->buildOverallPayload($shards, $metadata);
            }
        };

        $metadata = collect([
            'shard1' => (object) ['status' => 'active', 'record_count' => 3, 'created_at' => null, 'last_rebalanced_at' => null],
            'shard2' => (object) ['status' => 'active', 'record_count' => 1, 'created_at' => null, 'last_rebalanced_at' => null],
        ])->keyBy(fn ($item, $key) => $key);

        $payload = $command->exposedBuildOverallPayload(['shard1', 'shard2'], $metadata);

        $this->assertSame('ok', $payload['summary']['status']);
        $this->assertSame(2, $payload['summary']['total_shards']);
        $this->assertArrayHasKey('active_strategies', $payload['summary']);
        $this->assertArrayHasKey('default_strategy', $payload['summary']);
        $this->assertCount(2, $payload['shards']);
    }
}
