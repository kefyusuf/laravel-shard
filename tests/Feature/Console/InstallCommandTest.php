<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_it_rejects_unsupported_output_format(): void
    {
        $this->artisan('redis-shard:install', [
            '--format' => 'xml',
        ])
            ->expectsOutput('Unsupported format "xml". Allowed: table, json.')
            ->assertExitCode(1);
    }

    public function test_it_can_skip_migration_step(): void
    {
        $this->artisan('redis-shard:install', [
            '--skip-migrate' => true,
        ])
            ->expectsOutput('Installing Laravel Redis Sharding...')
            ->expectsOutput('Publishing configuration...')
            ->expectsOutput('Skipping migrations as requested.')
            ->expectsOutput('Laravel Redis Sharding installed successfully.')
            ->assertExitCode(0);
    }

    public function test_it_outputs_parseable_json_when_json_format_is_selected(): void
    {
        $this->artisan('redis-shard:install', [
            '--skip-migrate' => true,
            '--format' => 'json',
        ])
            ->assertExitCode(0);
    }
}
