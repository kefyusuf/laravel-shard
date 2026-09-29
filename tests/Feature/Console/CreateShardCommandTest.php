<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Tests\TestCase;

class CreateShardCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDeterministicRegistry();
    }

    protected function sqliteDatabasePath(string $suffix): string
    {
        return __DIR__ . '/../../tmp/' . $suffix . '.sqlite';
    }

    protected function prepareDeterministicRegistry(): void
    {
        $registryPath = __DIR__ . '/../../tmp/create-shard-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');
    }

    public function test_it_rejects_unsupported_output_format(): void
    {
        $this->artisan('shard:create', [
            'name' => 'format_test_shard',
            '--format' => 'xml',
        ])
            ->expectsOutput('Unsupported format "xml". Allowed: table, json.')
            ->assertExitCode(1);
    }

    public function test_it_can_create_new_shard(): void
    {
        $databasePath = $this->sqliteDatabasePath('create-shard-new');
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }

        $this->artisan('shard:create', [
            'name' => 'test_shard',
            '--driver' => 'sqlite',
            '--host' => 'localhost',
            '--port' => '1',
            '--database' => $databasePath,
            '--username' => 'ignored',
            '--password' => '',
            '--skip-migrate' => true,
        ])
        ->expectsOutput('Shard "test_shard" created successfully!')
        ->assertExitCode(0);

        // Verify metadata was created
        $metadata = ShardMetadata::where('name', 'test_shard')->first();
        $this->assertNotNull($metadata);
        $this->assertEquals('test_shard', $metadata->connection);
        $this->assertEquals('active', $metadata->status);
    }

    public function test_it_prevents_duplicate_shard_creation(): void
    {
        $databasePath = $this->sqliteDatabasePath('create-shard-duplicate');
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }

        // Create first shard
        $this->artisan('shard:create', [
            'name' => 'duplicate_shard',
            '--driver' => 'sqlite',
            '--host' => 'localhost',
            '--port' => '1',
            '--database' => $databasePath,
            '--username' => 'ignored',
            '--password' => '',
            '--skip-migrate' => true,
        ])->assertExitCode(0);

        // Try to create duplicate
        $this->artisan('shard:create', [
            'name' => 'duplicate_shard',
            '--driver' => 'sqlite',
            '--host' => 'localhost',
            '--port' => '1',
            '--database' => $this->sqliteDatabasePath('create-shard-duplicate-second'),
            '--username' => 'ignored',
            '--password' => '',
            '--skip-migrate' => true,
        ])
        ->expectsOutput('Shard "duplicate_shard" already exists!')
        ->assertExitCode(1);
    }

    public function test_it_requires_all_database_parameters(): void
    {
        $this->artisan('shard:create', [
            'name' => 'incomplete_shard',
            '--host' => '127.0.0.1',
            '--username' => '',
        ])
        ->expectsOutput('All database connection parameters are required.')
        ->assertExitCode(1);
    }

    public function test_it_can_create_shard_with_custom_driver(): void
    {
        $databasePath = $this->sqliteDatabasePath('create-shard-custom-driver');
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }

        $this->artisan('shard:create', [
            'name' => 'sqlite_shard_custom_driver',
            '--driver' => 'sqlite',
            '--host' => 'localhost',
            '--port' => '1',
            '--database' => $databasePath,
            '--username' => 'ignored',
            '--password' => '',
            '--skip-migrate' => true,
        ])
        ->expectsOutput('Shard "sqlite_shard_custom_driver" created successfully!')
        ->assertExitCode(0);

        $metadata = ShardMetadata::where('name', 'sqlite_shard_custom_driver')->first();
        $this->assertNotNull($metadata);
    }

    public function test_it_validates_shard_name(): void
    {
        $this->artisan('shard:create', [
            'name' => '', // Empty name
            '--host' => '127.0.0.1',
            '--port' => '3306',
            '--database' => 'test_db',
            '--username' => 'root',
            '--password' => 'secret',
        ])
        ->expectsOutput('Shard name cannot be empty.')
        ->assertExitCode(1);
    }
}
