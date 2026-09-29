<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Metrics;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Metrics\Pulse\RequestShardUsage;
use Laravel\RedisShard\Metrics\Pulse\ShardUsageCard;
use Laravel\RedisShard\ShardManager as BaseShardManager;
use Laravel\RedisShard\Tests\TestCase;

class PulseIntegrationTest extends TestCase
{
    public function test_shard_manager_reports_resolutions_to_the_usage_collector(): void
    {
        $shardPath = $this->prepareShardDatabase('pulse-usage');
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);
        $this->app->forgetInstance('shard.manager');
        ShardManager::clearResolvedInstances();

        $locator = $this->app->make(ShardLocatorInterface::class);
        $locator->register('users', 'a@example.com', 'shard1');
        $locator->register('users', 'b@example.com', 'shard2');

        ShardManager::getShardConnection('users', 'a@example.com');
        ShardManager::getShardConnection('users', 'a@example.com');
        ShardManager::getShardConnection('users', 'b@example.com');

        $usage = $this->app->make(RequestShardUsage::class);

        $this->assertSame(['shard1' => 2, 'shard2' => 1], $usage->flush());
    }

    public function test_shard_manager_works_without_a_usage_collector(): void
    {
        $shardPath = $this->prepareShardDatabase('pulse-usage-absent');
        $manager = new BaseShardManager(
            $this->app->make(Repository::class),
            $this->app->make(DatabaseManager::class),
            $this->app->make(ShardLocatorInterface::class),
        );

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);
        $this->app->forgetInstance('shard.manager');
        ShardManager::clearResolvedInstances();

        $this->assertSame('shard1', $manager->getShardConnection('users', 'a@example.com'));
    }

    public function test_pulse_card_and_view_are_registered(): void
    {
        $this->assertTrue(is_subclass_of(ShardUsageCard::class, Card::class));
        $this->assertTrue(View::exists('redis-shard::pulse.shard-usage'));
    }

    protected function prepareShardDatabase(string $suffix): string
    {
        $dir = __DIR__ . '/../../tmp';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . "pulse-{$suffix}.sqlite";
        if (file_exists($path)) {
            unlink($path);
        }
        touch($path);

        return $path;
    }
}
