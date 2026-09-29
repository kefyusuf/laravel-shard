<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use Laravel\RedisShard\Database\ShardableBuilder;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Traits\Shardable;

class ShardableTraitTest extends TestCase
{
    public function test_it_uses_shardable_builder_for_model_queries(): void
    {
        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];
        };

        $this->assertInstanceOf(ShardableBuilder::class, $model->newQuery());
    }

    public function test_it_can_set_explicit_shard_connection(): void
    {
        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];
        };

        $model->setShardConnection('shard1');

        $this->assertSame('shard1', $model->getConnectionName());
    }
}
