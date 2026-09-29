<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Commands\ShardHealthCommand;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Tests\TestCase;

class ShardHealthCommandPayloadTest extends TestCase
{
    public function test_build_summary_counts_severities_and_fixable_items(): void
    {
        $command = new class extends ShardHealthCommand {
            public function exposedBuildSummary(array $issues): array
            {
                return $this->buildSummary($issues);
            }
        };

        $summary = $command->exposedBuildSummary([
            ['severity' => 'critical', 'fixable' => false],
            ['severity' => 'medium', 'fixable' => true],
            ['severity' => 'high', 'fixable' => true],
        ]);

        $this->assertFalse($summary['healthy']);
        $this->assertSame(3, $summary['total_issues']);
        $this->assertSame(2, $summary['fixable_issues']);
        $this->assertSame(1, $summary['severity']['critical']);
        $this->assertSame(1, $summary['severity']['high']);
        $this->assertSame(1, $summary['severity']['medium']);
    }

    public function test_fix_payload_includes_exit_code_and_unresolved_count(): void
    {
        $command = new class extends ShardHealthCommand {
            public function exposedFixIssues(array $issues, ShardLocatorInterface $locator): array
            {
                return $this->fixIssues($issues, $locator);
            }

            public function info($string, $verbosity = null): void
            {
                // no-op for unit test
            }
        };

        $locator = new class implements ShardLocatorInterface {
            public function locate(string $table, mixed $key): ?string
            {
                return null;
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                return true;
            }

            public function forget(string $table, mixed $key): bool
            {
                return true;
            }

            public function getKeysForShard(string $table, string $shardConnection): array
            {
                return [];
            }
        };

        $result = $command->exposedFixIssues([
            ['type' => 'redis', 'fixable' => false, 'message' => 'redis down'],
        ], $locator);

        $this->assertSame(0, $result['fixed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['unresolved']);
        $this->assertSame(1, $result['exit_code']);
    }
}
