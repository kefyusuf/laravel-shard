<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Tests\TestCase;

class ShardStatusFormatValidationTest extends TestCase
{
    public function test_it_rejects_unsupported_output_format(): void
    {
        $this->artisan('shard:status', [
            '--format' => 'xml',
        ])
            ->expectsOutput('Unsupported format "xml". Allowed: table, json.')
            ->assertExitCode(1);
    }
}
