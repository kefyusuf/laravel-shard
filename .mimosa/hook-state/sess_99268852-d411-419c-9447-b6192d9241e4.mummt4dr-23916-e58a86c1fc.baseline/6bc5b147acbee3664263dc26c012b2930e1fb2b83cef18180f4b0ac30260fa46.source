<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Rebalance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Rebalance\DatabaseRebalanceDataMover;
use Laravel\RedisShard\Tests\TestCase;

class DatabaseRebalanceDataMoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureConnections();
        $this->migrateUsersTable();
    }

    public function test_it_moves_row_between_shards_and_deletes_source(): void
    {
        config()->set('redis_sharding.rebalance.delete_source_after_copy', true);
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'id']);

        DB::connection('source_shard')->table('users')->insert([
            'id' => 1,
            'name' => 'Alice',
        ]);

        $mover = new DatabaseRebalanceDataMover();
        $moved = $mover->move('users', 1, 'source_shard', 'target_shard');

        $this->assertTrue($moved);
        $this->assertSame(0, DB::connection('source_shard')->table('users')->where('id', 1)->count());
        $this->assertSame(1, DB::connection('target_shard')->table('users')->where('id', 1)->count());
    }

    public function test_it_returns_false_when_source_row_does_not_exist(): void
    {
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'id']);

        $mover = new DatabaseRebalanceDataMover();
        $moved = $mover->move('users', 999, 'source_shard', 'target_shard');

        $this->assertFalse($moved);
    }

    protected function configureConnections(): void
    {
        $databaseDir = __DIR__ . '/../../tmp';
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0777, true);
        }

        foreach (['source_shard', 'target_shard'] as $connectionName) {
            $databasePath = $databaseDir . DIRECTORY_SEPARATOR . $connectionName . '.sqlite';
            if (!file_exists($databasePath)) {
                touch($databasePath);
            }

            config()->set("database.connections.{$connectionName}", [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            DB::purge($connectionName);
        }
    }

    protected function migrateUsersTable(): void
    {
        foreach (['source_shard', 'target_shard'] as $connectionName) {
            Schema::connection($connectionName)->dropIfExists('users');
            Schema::connection($connectionName)->create('users', function ($table) {
                $table->increments('id');
                $table->string('name');
            });
        }
    }
}
