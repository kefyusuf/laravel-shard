<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
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
        $this->assertNotNull(DB::connection($shardName)->getPdo());
    }

    public function test_it_accepts_zero_port_value_for_sqlite_driver(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $shardName = "sqlite_shard_zero_port_{$suffix}";
        $databasePath = __DIR__ . "/../../tmp/new-shard-zero-port-{$suffix}.sqlite";
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }

        $this->artisan('shard:create', [
            'name' => $shardName,
            '--driver' => 'sqlite',
            '--host' => 'localhost',
            '--port' => '0',
            '--database' => $databasePath,
            '--username' => 'ignored',
            '--password' => '',
            '--skip-migrate' => true,
        ])
            ->expectsOutput("Shard \"{$shardName}\" created successfully!")
            ->assertExitCode(0);
    }

    public function test_it_outputs_parseable_json_when_json_format_is_selected(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $shardName = "sqlite_shard_json_{$suffix}";
        $databasePath = __DIR__ . "/../../tmp/new-shard-json-{$suffix}.sqlite";
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
            '--format' => 'json',
        ])->assertExitCode(0);
        $this->assertFileExists($databasePath);
        $this->assertNotNull(ShardMetadata::where('name', $shardName)->first());
    }
}
