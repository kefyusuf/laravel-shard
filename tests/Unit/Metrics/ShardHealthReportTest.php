<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Metrics\ShardHealthReport;
use Laravel\RedisShard\Tests\TestCase;

class ShardHealthReportTest extends TestCase
{
    public function test_reports_ok_when_all_shards_reachable(): void
    {
        $this->seedShards();

        $report = (new ShardHealthReport())->toArray();

        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['summary']['total']);
        $this->assertSame(2, $report['summary']['up']);
        $this->assertSame(0, $report['summary']['down']);
        $this->assertTrue($report['shards']['shard1']['reachable']);
    }

    public function test_reports_down_when_no_shards_configured(): void
    {
        config()->set('redis_sharding.connections', []);

        $report = (new ShardHealthReport())->toArray();

        $this->assertSame('down', $report['status']);
        $this->assertSame(0, $report['summary']['total']);
    }

    public function test_reports_degraded_when_one_shard_unreachable(): void
    {
        $this->seedShards();
        config()->set('database.connections.shard2', [
            'driver' => 'sqlite',
            'database' => '/definitely/missing/path.sqlite',
            'prefix' => '',
        ]);
        DB::purge('shard2');

        $report = (new ShardHealthReport())->toArray();

        $this->assertSame('degraded', $report['status']);
        $this->assertSame(1, $report['summary']['up']);
        $this->assertSame(1, $report['summary']['down']);
        $this->assertFalse($report['shards']['shard2']['reachable']);
    }

    public function test_health_route_is_registered_when_enabled(): void
    {
        $this->seedShards();
        config()->set('redis_sharding.metrics.enabled', true);
        config()->set('redis_sharding.metrics.path', '/shard-health');
        config()->set('redis_sharding.metrics.middleware', []);

        // Re-register routes with metrics enabled.
        $provider = new \Laravel\RedisShard\RedisShardServiceProvider($this->app);
        $provider->boot();

        $response = $this->getJson('/shard-health');
        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('summary.total', 2);
    }

    protected function seedShards(): void
    {
        $dir = __DIR__ . '/../../tmp';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $connections = [];
        foreach (['shard1', 'shard2'] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name . '-health-' . uniqid('', true) . '.sqlite';
            touch($path);
            $connections[$name] = [
                'driver' => 'sqlite',
                'database' => $path,
                'prefix' => '',
            ];
        }

        config()->set('redis_sharding.connections', $connections);
        config()->set('database.connections.shard1', $connections['shard1']);
        config()->set('database.connections.shard2', $connections['shard2']);

        $dbConfig = config('database.connections', []);
        unset($dbConfig['shard3']);
        config()->set('database.connections', $dbConfig);

        foreach (['shard1', 'shard2'] as $name) {
            DB::purge($name);
            Schema::connection($name)->create('health_probe', function ($table): void {
                $table->increments('id');
            });
        }

        $this->app->forgetInstance('shard.manager');
    }
}
