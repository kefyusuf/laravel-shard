<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\ShardManager;
use Laravel\RedisShard\Support\ShardRegistry;
use Laravel\RedisShard\Tests\TestCase;

class ShardManagerRegistryTest extends TestCase
{
    private string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registryPath = __DIR__ . '/../tmp/registry-test.json';
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

    private function makeManager(): ShardManager
    {
        return new ShardManager(
            $this->app->make(Repository::class),
            $this->app->make(DatabaseManager::class),
            $this->app->make(ShardLocatorInterface::class)
        );
    }
}
