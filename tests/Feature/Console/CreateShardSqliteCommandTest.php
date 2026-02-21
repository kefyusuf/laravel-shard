<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Tests\TestCase;

class CreateShardSqliteCommandTest extends TestCase
{
    public function test_it_creates_sqlite_shard_file_and_skips_migration_when_requested(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $shardName = "sqlite_shard_{$suffix}";
        $databasePath = __DIR__ . "/../../tmp/new-shard-{$suffix}.sqlite";
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }

        $this->artisan('shard:create', [
            'name' => $shardName,
            '--driver' => 'sqlite',
            '--host' => 'localhost',
            '--port' => '1',
            '--database' => $databasePath,
            '--username' => 'ignored',
            '--password' => '',
            '--skip-migrate' => true,
        ])
            ->expectsOutput("Shard \"{$shardName}\" created successfully!")
            ->assertExitCode(0);

        $this->assertFileExists($databasePath);
        $this->assertNotNull(ShardMetadata::where('name', $shardName)->first());
    }
}
