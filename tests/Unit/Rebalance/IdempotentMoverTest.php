<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Rebalance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Rebalance\DatabaseRebalanceDataMover;
use Laravel\RedisShard\Tests\TestCase;

class IdempotentMoverTest extends TestCase
{
    protected string $fromPath;

    protected string $toPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fromPath = $this->prepareDatabase('from');
        $this->toPath = $this->prepareDatabase('to');

        config()->set('database.connections.from', [
            'driver' => 'sqlite',
            'database' => $this->fromPath,
            'prefix' => '',
        ]);
        config()->set('database.connections.to', [
            'driver' => 'sqlite',
            'database' => $this->toPath,
            'prefix' => '',
        ]);
        config()->set('redis_sharding.rebalance.table_key_columns', ['users' => 'id']);
        config()->set('redis_sharding.rebalance.delete_source_after_copy', true);

        foreach (['from', 'to'] as $connection) {
            DB::purge($connection);
            Schema::connection($connection)->dropIfExists('users');
            Schema::connection($connection)->create('users', function ($table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('name');
            });
        }
    }

    public function test_move_copies_then_deletes_source(): void
    {
        DB::connection('from')->table('users')->insert(['id' => 1, 'name' => 'Ada']);

        $mover = new DatabaseRebalanceDataMover();
        $this->assertTrue($mover->move('users', 1, 'from', 'to'));

        $this->assertNull(DB::connection('from')->table('users')->where('id', 1)->first());
        $this->assertSame('Ada', DB::connection('to')->table('users')->where('id', 1)->value('name'));
    }

    public function test_move_is_idempotent_after_completion(): void
    {
        DB::connection('from')->table('users')->insert(['id' => 1, 'name' => 'Ada']);

        $mover = new DatabaseRebalanceDataMover();
        $this->assertTrue($mover->move('users', 1, 'from', 'to'));
        $this->assertTrue($mover->move('users', 1, 'from', 'to'));
    }

    public function test_move_recovers_from_copy_without_delete(): void
    {
        // Simulate an interrupted run: row exists on both shards.
        DB::connection('from')->table('users')->insert(['id' => 1, 'name' => 'Ada']);
        DB::connection('to')->table('users')->insert(['id' => 1, 'name' => 'Ada']);

        $mover = new DatabaseRebalanceDataMover();
        $this->assertTrue($mover->move('users', 1, 'from', 'to'));

        $this->assertNull(DB::connection('from')->table('users')->where('id', 1)->first());
        $this->assertSame('Ada', DB::connection('to')->table('users')->where('id', 1)->value('name'));
    }

    public function test_move_fails_when_row_is_missing_everywhere(): void
    {
        $mover = new DatabaseRebalanceDataMover();
        $this->assertFalse($mover->move('users', 99, 'from', 'to'));
    }

    protected function prepareDatabase(string $suffix): string
    {
        $dir = __DIR__ . '/../../tmp';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . 'rebalance-' . $suffix . '-' . uniqid('', true) . '.sqlite';
        touch($path);

        return $path;
    }
}
