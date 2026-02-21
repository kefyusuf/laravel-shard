<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Commands\ShardCleanupCommand;
use Laravel\RedisShard\Tests\TestCase;

class ShardCleanupCommandPayloadTest extends TestCase
{
    public function test_build_report_payload_has_expected_schema(): void
    {
        $command = new class extends ShardCleanupCommand {
            public function exposedBuildReportPayload(
                bool $dryRun,
                int $scannedKeys,
                int $orphanedKeys,
                int $staleMapEntries
            ): array {
                return $this->buildReportPayload($dryRun, $scannedKeys, $orphanedKeys, $staleMapEntries);
            }
        };

        $payload = $command->exposedBuildReportPayload(true, 10, 2, 1);

        $this->assertSame('ok', $payload['summary']['status']);
        $this->assertTrue($payload['summary']['dry_run']);
        $this->assertSame(10, $payload['summary']['scanned_keys']);
        $this->assertSame(2, $payload['summary']['orphaned_keys']);
        $this->assertSame(1, $payload['summary']['stale_map_entries']);

        $this->assertSame([
            'dry_run' => true,
            'scanned_keys' => 10,
            'orphaned_keys' => 2,
            'stale_map_entries' => 1,
        ], $payload['report']);
    }
}
