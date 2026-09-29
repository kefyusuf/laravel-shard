<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Database\ReadReplicaResolver;
use Laravel\RedisShard\Database\ShardExecutor;
use Laravel\RedisShard\Tests\TestCase;

class ShardExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['shard1', 'shard1_replica'] as $name) {
            config()->set("database.connections.{$name}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            DB::purge($name);
        }
        config()->set('database.default', 'testing');
        config()->set('redis_sharding.read_replicas.connections', ['shard1' => 'shard1_replica']);
    }

    private function executor(): ShardExecutor
    {
        return new ShardExecutor(app('db'), new ReadReplicaResolver(app('config')));
    }

    private function modelAndQuery(): array
    {
        $model = new class () extends Model {
            protected $table = 'users';
        };

        return [$model, $model->newQuery()->getQuery()];
    }

    public function test_run_pins_model_and_query_to_the_connection_and_restores_them(): void
    {
        [$model, $query] = $this->modelAndQuery();
        $originalModelConnection = $model->getConnectionName();
        $originalQueryConnection = $query->getConnection();
        $seen = null;

        $this->executor()->run('shard1', $model, $query, function () use (&$seen, $model, $query): void {
            $seen = [$model->getConnectionName(), $query->getConnection()];
        });

        $this->assertSame('shard1', $seen[0]);
        $this->assertSame(DB::connection('shard1'), $seen[1]);
        $this->assertSame($originalModelConnection, $model->getConnectionName());
        $this->assertSame($originalQueryConnection, $query->getConnection());
    }

    public function test_run_restores_state_even_when_the_operation_throws(): void
    {
        [$model, $query] = $this->modelAndQuery();
        $originalModelConnection = $model->getConnectionName();
        $originalQueryConnection = $query->getConnection();

        try {
            $this->executor()->run('shard1', $model, $query, function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected the exception to propagate.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($originalModelConnection, $model->getConnectionName());
        $this->assertSame($originalQueryConnection, $query->getConnection());
    }

    public function test_reads_resolve_through_the_replica_map(): void
    {
        [$model, $query] = $this->modelAndQuery();
        $seen = null;

        $this->executor()->runRead('shard1', $model, $query, function () use (&$seen, $model): void {
            $seen = $model->getConnectionName();
        });

        $this->assertSame('shard1_replica', $seen);
    }

    public function test_connection_for_read_falls_back_to_the_shard(): void
    {
        $this->assertSame('shard2', $this->executor()->connectionForRead('shard2'));
        $this->assertSame('shard1_replica', $this->executor()->connectionForRead('shard1'));
    }
}
