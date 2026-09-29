<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Commands\RebalanceShardCommand;
use Laravel\RedisShard\Tests\TestCase;

class RebalanceShardCommandPayloadTest extends TestCase
{
    public function test_error_payload_contains_summary_and_error_message(): void
    {
        $command = new class () extends RebalanceShardCommand {
            public function exposedBuildErrorPayload(string $table, bool $dryRun, bool $metadataOnly, string $error): array
            {
                return $this->buildErrorPayload($table, $dryRun, $metadataOnly, $error);
            }
        };

        $payload = $command->exposedBuildErrorPayload('users', false, true, 'test error');

        $this->assertSame('error', $payload['summary']['status']);
        $this->assertSame('users', $payload['summary']['table']);
        $this->assertSame('test error', $payload['error']);
    }

    public function test_dry_run_payload_contains_moves_and_summary_counts(): void
    {
        $command = new class () extends RebalanceShardCommand {
            public function exposedBuildDryRunPayload(
                string $table,
                bool $metadataOnly,
                string $strategy,
                int $totalKeys,
                array $moves
            ): array {
                return $this->buildDryRunPayload($table, $metadataOnly, $strategy, $totalKeys, $moves);
            }
        };

        $moves = [
            ['key' => '1', 'from' => 'shard1', 'to' => 'shard2'],
            ['key' => '2', 'from' => 'shard1', 'to' => 'shard2'],
        ];

        $payload = $command->exposedBuildDryRunPayload('users', true, 'modulo', 10, $moves);

        $this->assertSame('dry_run', $payload['summary']['status']);
        $this->assertSame(10, $payload['summary']['total_keys']);
        $this->assertSame(2, $payload['summary']['total_moves']);
        $this->assertSame($moves, $payload['moves']);
    }
}
