<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Traits\Shardable;

class ReadReplicaRoutingTest extends TestCase
{
    public function test_reads_route_to_the_configured_replica_connection(): void
    {
        $this->prepareEnvironment(['shard1' => 'shard1_replica']);

        $model = $this->model();

        $found = $model::query()->find(1);
        $this->assertNotNull($found);
        $this->assertSame('ReplicaRow', $found->name);

        // The replica only holds row 1: reads must not fall back to the shard.
        $this->assertNull($model::query()->find(2));
        $this->assertSame(1, $model::query()->where('id', 1)->count());
        $this->assertSame(1, $model::query()->where('id', 1)->get()->count());
    }

    public function test_models_hydrated_from_replica_reads_carry_the_shard_connection(): void
    {
        $this->prepareEnvironment(['shard1' => 'shard1_replica']);

        $model = $this->model();

        // Hydrated models must not carry the replica connection: a later
        // save() has to write to the shard, never to the replica.
        $found = $model::query()->find(1);
        $this->assertNotNull($found);
        $this->assertSame('shard1', $found->getConnectionName());

        foreach ($model::query()->where('id', 1)->get() as $listed) {
            $this->assertSame('shard1', $listed->getConnectionName());
        }
    }

    public function test_writes_still_target_the_shard_connection(): void
    {
        $this->prepareEnvironment(['shard1' => 'shard1_replica']);

        $model = $this->model();

        $model::query()->create(['id' => 3, 'name' => 'Written']);
        $model::query()->where('id', 3)->update(['name' => 'Updated']);

        $this->assertSame('Updated', DB::connection('shard1')->table('users')->where('id', 3)->value('name'));
        $this->assertSame(0, DB::connection('shard1_replica')->table('users')->where('id', 3)->count());
    }

    public function test_reads_fall_back_to_the_shard_without_replica_configured(): void
    {
        $this->prepareEnvironment([]);

        $model = $this->model();

        DB::connection('shard1')->table('users')->insert(['id' => 4, 'name' => 'OnShard']);

        $found = $model::query()->find(4);

        $this->assertNotNull($found);
        $this->assertSame('OnShard', $found->name);
    }

    private function model(): Model
    {
        return new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];
        };
    }

    /**
     * @param array<string, string> $replicas
     */
    private function prepareEnvironment(array $replicas): void
    {
        foreach (['shard1', 'shard1_replica'] as $name) {
            config()->set("database.connections.{$name}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            DB::purge($name);
            Schema::connection($name)->dropIfExists('users');
            Schema::connection($name)->create('users', function ($table): void {
                $table->increments('id');
                $table->string('name');
            });
        }

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        config()->set('redis_sharding.read_replicas.connections', $replicas);

        DB::connection('shard1_replica')->table('users')->insert(['id' => 1, 'name' => 'ReplicaRow']);

        $locator = new class () implements ShardLocatorInterface {
            public function locate(string $table, mixed $key): ?string
            {
                return 'shard1';
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                return true;
            }

            public function forget(string $table, mixed $key): bool
            {
                return true;
            }

            public function getKeysForShard(string $table, string $shardConnection): array
            {
                return [];
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);
        $this->app->forgetInstance('shard.manager');
        ShardManager::clearResolvedInstances();
    }
}
