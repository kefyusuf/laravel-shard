<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Queue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

use function Laravel\RedisShard\Queue\dispatchSharded;

use Laravel\RedisShard\Queue\ShardAwareJob;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Traits\Shardable;

class DispatchShardedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('redis_sharding.modules.queue', true);
    }

    public function test_dispatch_sharded_helper_sets_context(): void
    {
        Queue::fake();

        $model = $this->shardableModel();
        dispatchSharded(new DispatchShardedFixtureJob(), $model);

        Queue::assertPushed(DispatchShardedFixtureJob::class, function (DispatchShardedFixtureJob $job): bool {
            $this->assertSame('shard1', $job->getShardContext()?->connection);
            $this->assertSame('users', $job->getShardContext()?->table);
            $this->assertSame('user@example.com', $job->getShardContext()?->key);

            return true;
        });
    }

    public function test_static_dispatch_sharded_sets_context(): void
    {
        Queue::fake();

        $model = $this->shardableModel();
        DispatchShardedFixtureJob::dispatchSharded($model);

        Queue::assertPushed(DispatchShardedFixtureJob::class, 1);
    }

    public function test_dispatch_sharded_rejects_non_shard_aware_job(): void
    {
        Queue::fake();
        $model = $this->shardableModel();

        $this->expectException(\Laravel\RedisShard\Exceptions\ShardingException::class);
        $this->expectExceptionMessage('shard-aware job');

        dispatchSharded(new \stdClass(), $model);
    }

    public function test_upsert_splits_rows_across_shards(): void
    {
        $shard1 = $this->prepareShardDatabase('upsert-a');
        $shard2 = $this->prepareShardDatabase('upsert-b');

        $this->configureConnection('shard1', $shard1);
        $this->configureConnection('shard2', $shard2);

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shard1, 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => $shard2, 'prefix' => ''],
        ]);

        foreach (['shard1', 'shard2', 'testing'] as $connection) {
            DB::purge($connection);
            Schema::connection($connection)->dropIfExists('users');
            Schema::connection($connection)->create('users', function ($table): void {
                $table->increments('id');
                $table->string('email')->unique();
                $table->string('name');
            });
        }

        $locator = $this->app->make(\Laravel\RedisShard\Contracts\ShardLocatorInterface::class);
        $locator->register('users', 'a@example.com', 'shard1');
        $locator->register('users', 'b@example.com', 'shard2');

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];

            public function getShardKeyName(): string
            {
                return 'email';
            }
        };

        $result = $model::query()->upsert([
            ['email' => 'a@example.com', 'name' => 'Alice'],
            ['email' => 'b@example.com', 'name' => 'Bob'],
        ], ['email'], ['name']);

        $this->assertSame(2, $result);
        $this->assertSame('Alice', DB::connection('shard1')->table('users')->where('email', 'a@example.com')->value('name'));
        $this->assertSame('Bob', DB::connection('shard2')->table('users')->where('email', 'b@example.com')->value('name'));
    }

    public function test_upsert_with_empty_unique_by_fails_fast_before_writing_to_any_shard(): void
    {
        $shard1 = $this->prepareShardDatabase('upsert-empty-uniqueby-a');
        $shard2 = $this->prepareShardDatabase('upsert-empty-uniqueby-b');

        $this->configureConnection('shard1', $shard1);
        $this->configureConnection('shard2', $shard2);

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shard1, 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => $shard2, 'prefix' => ''],
        ]);

        foreach (['shard1', 'shard2'] as $connection) {
            DB::purge($connection);
            Schema::connection($connection)->dropIfExists('users');
            Schema::connection($connection)->create('users', function ($table): void {
                $table->increments('id');
                $table->string('email')->unique();
                $table->string('name');
            });
        }

        $locator = $this->app->make(\Laravel\RedisShard\Contracts\ShardLocatorInterface::class);
        $locator->register('users', 'a@example.com', 'shard1');
        $locator->register('users', 'b@example.com', 'shard2');

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];

            public function getShardKeyName(): string
            {
                return 'email';
            }
        };

        // Laravel 13 rejects an empty uniqueBy with InvalidArgumentException. The
        // sharded builder inherits that rejection through parent::upsert on every
        // routed leaf call, so the guarantee is: no shard ever receives rows when
        // uniqueBy is empty. This guards that no partial write regresses in.
        foreach ([[], ''] as $uniqueBy) {
            try {
                $model::query()->upsert([
                    ['email' => 'a@example.com', 'name' => 'Alice'],
                    ['email' => 'b@example.com', 'name' => 'Bob'],
                ], $uniqueBy, ['name']);
                $this->fail('Expected InvalidArgumentException for an empty uniqueBy.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('The unique columns must not be empty.', $exception->getMessage());
            }

            $this->assertSame(0, DB::connection('shard1')->table('users')->count());
            $this->assertSame(0, DB::connection('shard2')->table('users')->count());
        }
    }

    public function test_upsert_updates_existing_row_in_place(): void
    {
        $shard1 = $this->prepareShardDatabase('upsert-update');
        $this->configureConnection('shard1', $shard1);

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shard1, 'prefix' => ''],
        ]);

        DB::purge('shard1');
        Schema::connection('shard1')->dropIfExists('users');
        Schema::connection('shard1')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('name');
        });

        DB::connection('shard1')->table('users')->insert([
            'email' => 'a@example.com',
            'name' => 'Old',
        ]);

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];

            public function getShardKeyName(): string
            {
                return 'email';
            }

            public function getConnectionName(): ?string
            {
                return 'shard1';
            }
        };

        $model::query()->upsert([
            ['email' => 'a@example.com', 'name' => 'New'],
        ], ['email'], ['name']);

        $this->assertSame('New', DB::connection('shard1')->table('users')->where('email', 'a@example.com')->value('name'));
        $this->assertSame(1, DB::connection('shard1')->table('users')->count());
    }

    protected function shardableModel(): Model
    {
        return new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $fillable = ['email', 'name'];

            protected $attributes = [
                'email' => 'user@example.com',
                'name' => 'User',
            ];

            public function getShardKeyName(): string
            {
                return 'email';
            }

            public function getConnectionName(): ?string
            {
                return 'shard1';
            }
        };
    }

    protected function prepareShardDatabase(string $suffix): string
    {
        $dir = __DIR__ . '/../../tmp';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . "dispatch-shard-{$suffix}.sqlite";
        if (file_exists($path)) {
            unlink($path);
        }
        touch($path);

        return $path;
    }

    protected function configureConnection(string $name, string $databasePath): void
    {
        config()->set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
        ]);
    }
}

class DispatchShardedFixtureJob extends ShardAwareJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function handle(): void
    {
    }
}
