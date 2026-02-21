<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Query;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Traits\CrossShardQueryable;

class CrossShardQueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureShardConnections();

        foreach (['shard1', 'shard2', 'shard3'] as $shard) {
            Schema::connection($shard)->dropIfExists('events');
            Schema::connection($shard)->create('events', function ($table) {
                $table->increments('id');
                $table->integer('score');
            });
        }
    }

    protected function configureShardConnections(): void
    {
        $databaseDir = __DIR__ . '/../../tmp';
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0777, true);
        }

        foreach (['shard1', 'shard2', 'shard3'] as $shard) {
            $databasePath = $databaseDir . DIRECTORY_SEPARATOR . $shard . '.sqlite';
            if (!file_exists($databasePath)) {
                touch($databasePath);
            }

            config()->set("database.connections.{$shard}", [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            DB::purge($shard);
        }
    }

    public function test_it_applies_global_order_and_limit_across_shards(): void
    {
        DB::connection('shard1')->table('events')->insert([
            ['id' => 1, 'score' => 10],
            ['id' => 2, 'score' => 70],
        ]);
        DB::connection('shard2')->table('events')->insert([
            ['id' => 3, 'score' => 50],
        ]);
        DB::connection('shard3')->table('events')->insert([
            ['id' => 4, 'score' => 90],
            ['id' => 5, 'score' => 30],
        ]);

        $scores = EventModel::crossShard()
            ->orderBy('score', 'desc')
            ->limit(2)
            ->get()
            ->pluck('score')
            ->all();

        $this->assertSame([90, 70], $scores);
    }

    public function test_it_applies_global_order_offset_and_limit_across_shards(): void
    {
        DB::connection('shard1')->table('events')->insert([
            ['id' => 1, 'score' => 10],
            ['id' => 2, 'score' => 70],
        ]);
        DB::connection('shard2')->table('events')->insert([
            ['id' => 3, 'score' => 50],
        ]);
        DB::connection('shard3')->table('events')->insert([
            ['id' => 4, 'score' => 90],
            ['id' => 5, 'score' => 30],
        ]);

        $scores = EventModel::crossShard()
            ->orderBy('score', 'desc')
            ->offset(1)
            ->limit(2)
            ->get()
            ->pluck('score')
            ->all();

        $this->assertSame([70, 50], $scores);
    }
}

class EventModel extends Model
{
    use CrossShardQueryable;

    protected $table = 'events';
    public $timestamps = false;
    protected $guarded = [];
}
