<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Tests\TestCase;

class ShardHealthCommandTest extends TestCase
{
    public function test_fix_mode_returns_failure_when_unresolved_issues_remain(): void
    {
        $this->prepareDeterministicHealthIssues();

        $this->artisan('shard:health', [
            '--fix' => true,
            '--format' => 'json',
        ])->assertExitCode(1);
    }

    public function test_json_format_includes_summary_and_issues_blocks(): void
    {
        $this->prepareDeterministicHealthIssues();

        $this->artisan('shard:health', [
            '--format' => 'json',
        ])
            ->assertExitCode(1);
    }

    public function test_fix_json_includes_fix_result_block(): void
    {
        $this->prepareDeterministicHealthIssues();

        $this->artisan('shard:health', [
            '--fix' => true,
            '--format' => 'json',
        ])
            ->assertExitCode(1);
    }

    protected function prepareDeterministicHealthIssues(): void
    {
        $registryPath = __DIR__ . '/../../tmp/health-json-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        config()->set('redis_sharding.connections', [
            'broken_shard' => [
                'driver' => 'invalid_driver',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        config()->set('redis_sharding.monitored_tables', ['users']);

        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');
    }
}
