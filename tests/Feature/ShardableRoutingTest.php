<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
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

        $locator = new class implements ShardLocatorInterface {
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

        $manager = new class {
            public function getShardConnection(string $table, mixed $key): string
            {
                return 'shard1';
            }
        };

        Facade::clearResolvedInstance('shard.manager');
        $this->app->instance('shard.manager', $manager);

        $model = new class extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $fillable = ['email', 'name'];
            protected $shardKey = 'email';
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

        $locator = new class implements ShardLocatorInterface {
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

        $manager = new class {
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

        $model = new class extends Model {
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

    protected function prepareShardDatabase(string $suffix): string
    {
        $dir = __DIR__ . '/../tmp';
        if (!is_dir($dir)) {
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
