<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Tests\TestCase;

class ShardAnalyzeCommandTest extends TestCase
{
    public function test_it_rejects_invalid_sample_size(): void
    {
        $this->artisan('shard:analyze', [
            '--sample-size' => 0,
        ])
            ->expectsOutput('Sample size must be at least 2.')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_no_shards_are_configured(): void
    {
        config()->set('redis_sharding.connections', []);

        $this->artisan('shard:analyze', [
            '--sample-size' => 10,
        ])
            ->expectsOutput('No available shards found.')
            ->assertExitCode(1);
    }
}
