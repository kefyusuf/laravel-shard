<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\ShardManager;
use Laravel\RedisShard\Support\ShardRegistry;
use Laravel\RedisShard\Tests\TestCase;

class ShardManagerRegistryTest extends TestCase
{
    private string $registryPath;
    private string $runtimeShardPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registryPath = __DIR__ . '/../tmp/registry-test.json';
        $this->runtimeShardPath = __DIR__ . '/../tmp/runtime-shard.sqlite';
        if (!is_dir(dirname($this->registryPath))) {
            mkdir(dirname($this->registryPath), 0777, true);
        }

        if (file_exists($this->registryPath)) {
            unlink($this->registryPath);
        }

        config()->set('redis_sharding.registry_path', $this->registryPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->registryPath)) {
            unlink($this->registryPath);
        }
        if (file_exists($this->runtimeShardPath)) {
            unlink($this->runtimeShardPath);
        }

        parent::tearDown();
    }

    public function test_it_hydrates_connections_from_registry(): void
    {
        ShardRegistry::writeAll([
            'persisted_shard' => [
                'driver' => 'sqlite',
                'host' => 'localhost',
                'database' => ':memory:',
                'username' => 'root',
                'password' => '',
            ],
        ]);

        config()->set('redis_sharding.connections', [
            'configured_shard' => [
                'driver' => 'sqlite',
                'host' => 'localhost',
                'database' => ':memory:',
                'username' => 'root',
                'password' => '',
            ],
        ]);

        $manager = $this->makeManager();
        $shards = $manager->getAvailableShards();

        $this->assertContains('persisted_shard', $shards);
        $this->assertContains('configured_shard', $shards);
    }

    public function test_it_persists_new_shard_in_registry_when_created(): void
    {
        $manager = $this->makeManager();

        $created = $manager->createShard('dynamic_shard', [
            'driver' => 'sqlite',
            'host' => 'localhost',
            'database' => ':memory:',
            'username' => 'root',
            'password' => '',
        ]);

        $this->assertTrue($created);
        $persisted = ShardRegistry::readAll();
        $this->assertArrayHasKey('dynamic_shard', $persisted);
    }

    public function test_it_registers_new_shard_in_database_connections_runtime_config(): void
    {
        if (file_exists($this->runtimeShardPath)) {
            unlink($this->runtimeShardPath);
        }
        touch($this->runtimeShardPath);

        $manager = $this->makeManager();
        $created = $manager->createShard('runtime_shard', [
            'driver' => 'sqlite',
            'database' => $this->runtimeShardPath,
            'prefix' => '',
        ]);

        $this->assertTrue($created);
        $this->assertSame('sqlite', config('database.connections.runtime_shard.driver'));
        $this->assertNotNull(DB::connection('runtime_shard')->getPdo());
    }

    public function test_it_respects_metadata_table_configuration_when_creating_shards(): void
    {
        config()->set('redis_sharding.metadata_table', 'custom_shard_metadata');

        Schema::dropIfExists('custom_shard_metadata');
        Schema::create('custom_shard_metadata', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('connection');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('record_count')->default(0);
            $table->timestamp('last_rebalanced_at')->nullable();
            $table->timestamps();
        });

        $manager = $this->makeManager();
        $created = $manager->createShard('metadata_shard', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $this->assertTrue($created);
        $this->assertTrue(DB::table('custom_shard_metadata')->where('name', 'metadata_shard')->exists());
    }

    private function makeManager(): ShardManager
    {
        return new ShardManager(
            $this->app->make(Repository::class),
            $this->app->make(DatabaseManager::class),
            $this->app->make(ShardLocatorInterface::class),
            $this->app
        );
    }
}
