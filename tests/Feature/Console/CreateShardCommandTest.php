<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Laravel\RedisShard\Models\ShardMetadata;
use Laravel\RedisShard\Tests\TestCase;

class CreateShardCommandTest extends TestCase
{
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
        $this->artisan('shard:create', [
            'name' => 'test_shard',
            '--host' => '127.0.0.1',
            '--port' => '3306',
            '--database' => 'test_db',
            '--username' => 'root',
            '--password' => 'secret',
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
        // Create first shard
        $this->artisan('shard:create', [
            'name' => 'duplicate_shard',
            '--host' => '127.0.0.1',
            '--port' => '3306',
            '--database' => 'test_db',
            '--username' => 'root',
            '--password' => 'secret',
        ])->assertExitCode(0);
        
        // Try to create duplicate
        $this->artisan('shard:create', [
            'name' => 'duplicate_shard',
            '--host' => '127.0.0.1',
            '--port' => '3306',
            '--database' => 'test_db2',
            '--username' => 'root',
            '--password' => 'secret',
        ])
        ->expectsOutput('Shard "duplicate_shard" already exists!')
        ->assertExitCode(1);
    }

    public function test_it_requires_all_database_parameters(): void
    {
        $this->artisan('shard:create', [
            'name' => 'incomplete_shard',
            '--host' => '127.0.0.1',
            // Missing other required parameters
        ])
        ->expectsOutput('All database connection parameters are required.')
        ->assertExitCode(1);
    }

    public function test_it_can_create_shard_with_custom_driver(): void
    {
        $this->artisan('shard:create', [
            'name' => 'postgres_shard',
            '--driver' => 'pgsql',
            '--host' => '127.0.0.1',
            '--port' => '5432',
            '--database' => 'postgres_db',
            '--username' => 'postgres',
            '--password' => 'secret',
        ])
        ->expectsOutput('Shard "postgres_shard" created successfully!')
        ->assertExitCode(0);
        
        $metadata = ShardMetadata::where('name', 'postgres_shard')->first();
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
