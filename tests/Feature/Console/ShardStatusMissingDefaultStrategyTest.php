<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Tests\TestCase;

class ShardStatusMissingDefaultStrategyTest extends TestCase
{
    public function test_overall_status_falls_back_when_default_strategy_is_unavailable(): void
    {
        $registryPath = __DIR__ . '/../../tmp/status-missing-default-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        config()->set('redis_sharding.default_strategy', 'not_defined');
        config()->set('redis_sharding.strategies', []);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');

        $this->artisan('shard:status')
            ->expectsOutput('Default Strategy: N/A')
            ->assertExitCode(0);
    }
}
