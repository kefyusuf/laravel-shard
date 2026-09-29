<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Metrics;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Metrics\ShardDiagnosticReport;
use Laravel\RedisShard\Tests\TestCase;

class ShardDiagnosticReportTest extends TestCase
{
    public function test_detailed_report_includes_modules_locator_and_issues(): void
    {
        $this->seedShards();

        $report = (new ShardDiagnosticReport())->toArray();

        $this->assertArrayHasKey('modules', $report);
        $this->assertArrayHasKey('locator', $report);
        $this->assertArrayHasKey('distribution', $report);
        $this->assertArrayHasKey('issues', $report);
        $this->assertArrayHasKey('strategy', $report['summary']);
        $this->assertSame(2, $report['summary']['total']);
        $this->assertIsArray($report['issues']);
    }

    public function test_detailed_report_flags_unreachable_shard(): void
    {
        $this->seedShards();
        config()->set('database.connections.shard2', [
            'driver' => 'sqlite',
            'database' => '/definitely/missing/path.sqlite',
            'prefix' => '',
        ]);
        DB::purge('shard2');

        $report = (new ShardDiagnosticReport())->toArray();

        $this->assertSame('degraded', $report['status']);
        $this->assertFalse($report['shards']['shard2']['reachable']);
        $this->assertNotSame([], $report['issues']);
    }

    public function test_health_endpoint_supports_detail_flag(): void
    {
        $this->seedShards();
        config()->set('redis_sharding.metrics.enabled', true);
        config()->set('redis_sharding.metrics.path', '/shard-health');
        config()->set('redis_sharding.metrics.middleware', []);

        $provider = new \Laravel\RedisShard\RedisShardServiceProvider($this->app);
        $provider->boot();

        $response = $this->getJson('/shard-health?detail=1');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'summary',
                'shards',
                'distribution',
                'locator',
                'modules',
                'issues',
            ]);
    }

    public function test_shard_report_command_json(): void
    {
        $this->seedShards();

        $exit = Artisan::call('shard:report', ['--format' => 'json']);
        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('shards', $payload);
        $this->assertArrayHasKey('modules', $payload);
    }

    protected function seedShards(): void
    {
        $dir = __DIR__ . '/../../tmp';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $connections = [];
        foreach (['shard1', 'shard2'] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name . '-diag-' . uniqid('', true) . '.sqlite';
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
            Schema::connection($name)->create('diag_probe', function ($table): void {
                $table->increments('id');
            });
        }

        $this->app->forgetInstance('shard.manager');
    }
}
