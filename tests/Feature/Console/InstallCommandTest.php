<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Tests\TestCase;

class InstallCommandTest extends TestCase
{
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
}
