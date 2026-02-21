<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Tests\TestCase;

class ShardStatusNoShardsTest extends TestCase
{
    public function test_it_fails_when_no_shards_are_configured(): void
    {
        $registryPath = __DIR__ . '/../../tmp/status-no-shards-registry.json';
        if (file_exists($registryPath)) {
            unlink($registryPath);
        }

        config()->set('redis_sharding.registry_path', $registryPath);
        config()->set('redis_sharding.connections', []);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');

        $this->artisan('shard:status')
            ->expectsOutput('No available shards found.')
            ->assertExitCode(1);
    }
}
