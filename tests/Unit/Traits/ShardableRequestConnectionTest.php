<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Traits\Shardable;

class ShardableRequestConnectionTest extends TestCase
{
    public function test_it_uses_request_shard_connection_when_shard_key_is_missing(): void
    {
        $request = Request::create('/users/1', 'GET');
        $request->attributes->set('shard_connection', 'testing');
        $this->app->instance('request', $request);

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];
        };

        $model->setShardConnection();

        $this->assertSame('testing', $model->getConnectionName());
    }

    public function test_builder_initialization_applies_request_shard_connection(): void
    {
        $request = Request::create('/users/1', 'GET');
        $request->attributes->set('shard_connection', 'testing');
        $this->app->instance('request', $request);

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];
        };

        $model->newQuery();

        $this->assertSame('testing', $model->getConnectionName());
    }
}
