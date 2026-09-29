<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Traits\Shardable;

class ShardableRoutingTest extends TestCase
{
    public function test_creating_event_routes_to_shard_even_with_default_connection_present(): void
    {
        $shardPath = $this->prepareShardDatabase('create-routing');

        $this->configureConnection('shard1', $shardPath);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);

        DB::purge('testing');
        DB::purge('shard1');

        Schema::connection('testing')->dropIfExists('users');
        Schema::connection('shard1')->dropIfExists('users');

        Schema::connection('testing')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
        });

        Schema::connection('shard1')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
        });

        $locator = new class () implements ShardLocatorInterface {
            /** @var array<string, string> */
            public array $registered = [];

            public function locate(string $table, mixed $key): ?string
            {
                return null;
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                $this->registered[$table . ':' . (string) $key] = $shardConnection;

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

        $manager = new class () {
            public function getShardConnection(string $table, mixed $key): string
            {
                return 'shard1';
            }
        };

        Facade::clearResolvedInstance('shard.manager');
        $this->app->instance('shard.manager', $manager);

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $fillable = ['email', 'name'];

            public function getShardKeyName(): string
            {
                return 'email';
            }
        };

        $model::create([
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ]);

        $this->assertSame(0, DB::connection('testing')->table('users')->count());
        $this->assertSame(1, DB::connection('shard1')->table('users')->count());
        $this->assertSame('shard1', $locator->registered['users:alice@example.com'] ?? null);
    }

    public function test_find_and_first_fallback_to_strategy_when_locator_misses(): void
    {
        $shardPath = $this->prepareShardDatabase('builder-fallback');

        $this->configureConnection('shard2', $shardPath);
        config()->set('redis_sharding.connections', [
            'shard2' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);

        DB::purge('testing');
        DB::purge('shard2');

        Schema::connection('testing')->dropIfExists('users');
        Schema::connection('shard2')->dropIfExists('users');

        Schema::connection('testing')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
        });

        Schema::connection('shard2')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
        });

        DB::connection('shard2')->table('users')->insert([
            'id' => 101,
            'email' => 'fallback@example.com',
            'name' => 'Fallback',
        ]);

        $locator = new class () implements ShardLocatorInterface {
            public function locate(string $table, mixed $key): ?string
            {
                return null;
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

        $manager = new class () {
            public function getShardConnection(string $table, mixed $key): string
            {
                return 'shard2';
            }

            public function getAvailableShards(): array
            {
                return ['shard2'];
            }
        };

        Facade::clearResolvedInstance('shard.manager');
        $this->app->instance('shard.manager', $manager);

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $guarded = [];
        };

        $foundByFind = $model::query()->find(101);
        $foundByFirst = $model::query()->where('id', 101)->first();
        $foundByMany = $model::query()->findMany([101]);

        $this->assertNotNull($foundByFind);
        $this->assertSame('fallback@example.com', $foundByFind?->email);
        $this->assertNotNull($foundByFirst);
        $this->assertSame('fallback@example.com', $foundByFirst?->email);
        $this->assertCount(1, $foundByMany);
    }

    public function test_query_builder_routes_single_shard_read_and_write_operations(): void
    {
        $shardPath = $this->prepareShardDatabase('builder-operations');

        $this->configureConnection('shard2', $shardPath);
        config()->set('redis_sharding.connections', [
            'shard2' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);

        DB::purge('testing');
        DB::purge('shard2');

        Schema::connection('testing')->dropIfExists('users');
        Schema::connection('shard2')->dropIfExists('users');

        Schema::connection('testing')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->unsignedInteger('visits')->default(0);
            $table->timestamps();
        });

        Schema::connection('shard2')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->unsignedInteger('visits')->default(0);
            $table->timestamps();
        });

        $now = now()->subHour();

        DB::connection('shard2')->table('users')->insert([
            'id' => 101,
            'email' => 'ops@example.com',
            'name' => 'Before',
            'visits' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $locator = new class () implements ShardLocatorInterface {
            public function locate(string $table, mixed $key): ?string
            {
                return $key === 101 ? 'shard2' : null;
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

        $manager = new class () {
            public function getShardConnection(string $table, mixed $key): string
            {
                return 'shard2';
            }

            public function getAvailableShards(): array
            {
                return ['shard2'];
            }
        };

        Facade::clearResolvedInstance('shard.manager');
        $this->app->instance('shard.manager', $manager);

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            protected $guarded = [];
        };

        $this->assertSame(1, $model::query()->where('id', 101)->count());
        $this->assertTrue($model::query()->where('id', 101)->exists());
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->value('email'));
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->valueOrFail('email'));
        $this->assertSame(['ops@example.com'], $model::query()->where('id', 101)->pluck('email')->all());
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->sole()->email);
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->soleValue('email'));
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->get()->pluck('email')->first());
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->cursor()->first()?->email);
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->lazy(10)->first()?->email);
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->lazyById(10)->first()?->email);
        $this->assertSame(1, $model::query()->where('id', 101)->paginate(15)->total());
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->simplePaginate(15)->items()[0]->email);
        $this->assertSame('ops@example.com', $model::query()->where('id', 101)->cursorPaginate(15)->items()[0]->email);

        $chunkedEmails = [];
        $model::query()->where('id', 101)->chunk(50, function ($rows) use (&$chunkedEmails): void {
            foreach ($rows as $row) {
                $chunkedEmails[] = $row->email;
            }
        });

        $this->assertSame(['ops@example.com'], $chunkedEmails);

        $chunkedByIdEmails = [];
        $model::query()->where('id', 101)->chunkById(10, function ($rows) use (&$chunkedByIdEmails): void {
            foreach ($rows as $row) {
                $chunkedByIdEmails[] = $row->email;
            }
        });

        $this->assertSame(['ops@example.com'], $chunkedByIdEmails);

        $eachByIdEmails = [];
        $model::query()->where('id', 101)->eachById(function ($row) use (&$eachByIdEmails): void {
            $eachByIdEmails[] = $row->email;
        }, 10);

        $this->assertSame(['ops@example.com'], $eachByIdEmails);

        $updated = $model::query()->where('id', 101)->update(['name' => 'After']);
        $this->assertSame(1, $updated);
        $this->assertSame('After', DB::connection('shard2')->table('users')->where('id', 101)->value('name'));
        $this->assertSame(0, DB::connection('testing')->table('users')->count());

        $incremented = $model::query()->where('id', 101)->increment('visits', 2);
        $this->assertSame(1, $incremented);
        $this->assertSame(3, DB::connection('shard2')->table('users')->where('id', 101)->value('visits'));

        $decremented = $model::query()->where('id', 101)->decrement('visits');
        $this->assertSame(1, $decremented);
        $this->assertSame(2, DB::connection('shard2')->table('users')->where('id', 101)->value('visits'));

        $touched = $model::query()->where('id', 101)->touch();
        $this->assertSame(1, $touched);
        $this->assertNotSame(
            $now->toDateTimeString(),
            DB::connection('shard2')->table('users')->where('id', 101)->value('updated_at')
        );

        $deleted = $model::query()->where('id', 101)->delete();
        $this->assertSame(1, $deleted);
        $this->assertSame(0, DB::connection('shard2')->table('users')->count());
        $this->assertSame(0, DB::connection('testing')->table('users')->count());
    }

    public function test_upsert_routes_records_using_the_shard_key(): void
    {
        $shardPath = $this->prepareShardDatabase('builder-upsert');

        $this->configureConnection('shard1', $shardPath);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);

        DB::purge('testing');
        DB::purge('shard1');

        Schema::connection('testing')->dropIfExists('users');
        Schema::connection('shard1')->dropIfExists('users');

        Schema::connection('testing')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('name');
        });

        Schema::connection('shard1')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('name');
        });

        $locator = new class () implements ShardLocatorInterface {
            public function locate(string $table, mixed $key): ?string
            {
                return null;
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

        $manager = new class () {
            public function getShardConnection(string $table, mixed $key): string
            {
                return 'shard1';
            }

            public function getAvailableShards(): array
            {
                return ['shard1'];
            }
        };

        Facade::clearResolvedInstance('shard.manager');
        $this->app->instance('shard.manager', $manager);

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
            ['email' => 'upsert@example.com', 'name' => 'Inserted'],
        ], ['email'], ['name']);

        $this->assertSame(1, $result);
        $this->assertSame(0, DB::connection('testing')->table('users')->count());
        $this->assertSame('Inserted', DB::connection('shard1')->table('users')->where('email', 'upsert@example.com')->value('name'));
    }

    public function test_auto_increment_models_without_explicit_shard_resolution_fail_fast(): void
    {
        $shardPath = $this->prepareShardDatabase('auto-increment-guard');

        $this->configureConnection('shard1', $shardPath);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);

        DB::purge('testing');
        DB::purge('shard1');

        Schema::connection('testing')->dropIfExists('users');
        Schema::connection('testing')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('name');
        });

        $model = new class () extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $fillable = ['name'];
        };

        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('Cannot resolve shard connection for new users record before insert.');

        $model::create(['name' => 'Silent Default']);
    }

    protected function prepareShardDatabase(string $suffix): string
    {
        $dir = __DIR__ . '/../tmp';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $shardPath = $dir . DIRECTORY_SEPARATOR . "shard-{$suffix}.sqlite";

        if (file_exists($shardPath)) {
            unlink($shardPath);
        }

        touch($shardPath);

        return $shardPath;
    }

    protected function configureConnection(string $name, string $databasePath): void
    {
        config()->set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
