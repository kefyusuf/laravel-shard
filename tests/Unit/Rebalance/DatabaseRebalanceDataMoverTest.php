<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Rebalance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Exceptions\ShardingException;
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
        $this->assertSame(0, $mover->fencedMoves());
    }

    public function test_concurrent_write_during_copy_fences_the_delete_and_propagates_the_latest_row(): void
    {
        config()->set('redis_sharding.rebalance.delete_source_after_copy', true);
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'id']);

        DB::connection('source_shard')->table('users')->insert([
            'id' => 1,
            'name' => 'Alice',
        ]);

        // Simulates a concurrent writer that commits while the copy runs:
        // the copy lands, then the source row changes before the delete.
        $mover = new class () extends DatabaseRebalanceDataMover {
            public function copyRow(string $connection, string $table, string $keyColumn, mixed $keyValue, array $payload): void
            {
                parent::copyRow($connection, $table, $keyColumn, $keyValue, $payload);

                if ($connection === 'target_shard') {
                    DB::connection('source_shard')->table($table)->where($keyColumn, $keyValue)->update(['name' => 'Concurrent']);
                }
            }
        };

        $moved = $mover->move('users', 1, 'source_shard', 'target_shard');

        $this->assertTrue($moved);
        $this->assertSame(1, $mover->fencedMoves());
        $this->assertSame(1, DB::connection('source_shard')->table('users')->where('id', 1)->count());
        $this->assertSame('Concurrent', DB::connection('source_shard')->table('users')->where('id', 1)->value('name'));
        $this->assertSame('Concurrent', DB::connection('target_shard')->table('users')->where('id', 1)->value('name'));
    }

    public function test_fencing_can_be_disabled(): void
    {
        config()->set('redis_sharding.rebalance.delete_source_after_copy', true);
        config()->set('redis_sharding.rebalance.fence_enabled', false);
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'id']);

        DB::connection('source_shard')->table('users')->insert([
            'id' => 1,
            'name' => 'Alice',
        ]);

        $mover = new class () extends DatabaseRebalanceDataMover {
            public function copyRow(string $connection, string $table, string $keyColumn, mixed $keyValue, array $payload): void
            {
                parent::copyRow($connection, $table, $keyColumn, $keyValue, $payload);

                if ($connection === 'target_shard') {
                    DB::connection('source_shard')->table($table)->where($keyColumn, $keyValue)->update(['name' => 'Concurrent']);
                }
            }
        };

        $moved = $mover->move('users', 1, 'source_shard', 'target_shard');

        $this->assertTrue($moved);
        $this->assertSame(0, $mover->fencedMoves());
        $this->assertSame(0, DB::connection('source_shard')->table('users')->where('id', 1)->count());
        $this->assertSame('Alice', DB::connection('target_shard')->table('users')->where('id', 1)->value('name'));
    }

    public function test_it_returns_false_when_source_row_does_not_exist(): void
    {
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'id']);

        $mover = new DatabaseRebalanceDataMover();
        $moved = $mover->move('users', 999, 'source_shard', 'target_shard');

        $this->assertFalse($moved);
    }

    public function test_non_unique_source_key_is_rejected_before_either_shard_changes(): void
    {
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'name']);
        DB::connection('source_shard')->table('users')->insert([
            ['id' => 1, 'name' => 'Shared'],
            ['id' => 2, 'name' => 'Shared'],
        ]);

        try {
            (new DatabaseRebalanceDataMover())->move('users', 'Shared', 'source_shard', 'target_shard');
        } catch (ShardingException $exception) {
            $this->assertSame([1, 2], DB::connection('source_shard')->table('users')->orderBy('id')->pluck('id')->all());
            $this->assertSame(0, DB::connection('target_shard')->table('users')->count());

            return;
        }

        $this->fail('A non-unique source key must be rejected before copying or deleting rows.');
    }

    public function test_write_after_reread_is_not_deleted(): void
    {
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'id']);
        config()->set('redis_sharding.rebalance.delete_source_after_copy', true);
        config()->set('redis_sharding.rebalance.fence_enabled', true);
        $source = DB::connection('source_shard');
        $source->table('users')->insert(['id' => 1, 'name' => 'Alice']);
        $wrote = false;

        // Commit a real write through another connection immediately before
        // the source DELETE executes, after the mover's final SELECT.
        config()->set('database.connections.concurrent_writer', config('database.connections.source_shard'));
        DB::purge('concurrent_writer');
        $source->beforeExecuting(function (string $query) use (&$wrote): void {
            if (! $wrote && str_starts_with(strtolower($query), 'delete')) {
                $wrote = true;
                DB::connection('concurrent_writer')->table('users')->where('id', 1)->update(['name' => 'Late write']);
            }
        });

        try {
            $mover = new DatabaseRebalanceDataMover();
            $this->assertTrue($mover->move('users', 1, 'source_shard', 'target_shard'));
            $this->assertTrue($wrote);
            $this->assertSame('Late write', $source->table('users')->where('id', 1)->value('name'));
            $this->assertSame('Late write', DB::connection('target_shard')->table('users')->where('id', 1)->value('name'));
            $this->assertSame(1, $mover->fencedMoves());
        } finally {
            DB::purge('concurrent_writer');
        }
    }

    public function test_case_insensitive_collation_does_not_hide_a_write_after_reread(): void
    {
        config()->set('redis_sharding.rebalance.table_key_columns', ['case_users' => 'id']);
        config()->set('redis_sharding.rebalance.delete_source_after_copy', true);
        config()->set('redis_sharding.rebalance.fence_enabled', true);
        foreach (['source_shard', 'target_shard'] as $connection) {
            DB::connection($connection)->statement('CREATE TABLE case_users (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE, note TEXT NULL)');
        }
        $source = DB::connection('source_shard');
        $source->table('case_users')->insert(['id' => 1, 'name' => 'Alice', 'note' => null]);
        config()->set('database.connections.concurrent_writer', config('database.connections.source_shard'));
        DB::purge('concurrent_writer');
        $wrote = false;
        $source->beforeExecuting(function (string $query) use (&$wrote): void {
            if (! $wrote && str_starts_with(strtolower($query), 'delete')) {
                $wrote = true;
                DB::connection('concurrent_writer')->table('case_users')->where('id', 1)->update(['name' => 'ALICE']);
            }
        });

        try {
            $mover = new DatabaseRebalanceDataMover();
            $this->assertTrue($mover->move('case_users', 1, 'source_shard', 'target_shard'));
            $this->assertTrue($wrote);
            $this->assertSame('ALICE', $source->table('case_users')->where('id', 1)->value('name'));
            $this->assertSame('ALICE', DB::connection('target_shard')->table('case_users')->where('id', 1)->value('name'));
            $this->assertSame(1, $mover->fencedMoves());
        } finally {
            DB::purge('concurrent_writer');
        }
    }

    protected function configureConnections(): void
    {
        $databaseDir = __DIR__ . '/../../tmp';
        if (! is_dir($databaseDir)) {
            mkdir($databaseDir, 0777, true);
        }

        foreach (['source_shard', 'target_shard'] as $connectionName) {
            $databasePath = $databaseDir . DIRECTORY_SEPARATOR . $connectionName . '.sqlite';
            if (! file_exists($databasePath)) {
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
