<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Tests\TestCase;

class ShardHealthCommandTest extends TestCase
{
    public function test_fix_mode_returns_failure_when_unresolved_issues_remain(): void
    {
        $this->artisan('shard:health', [
            '--fix' => true,
            '--format' => 'json',
        ])->assertExitCode(1);
    }
}
