<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Metrics;

use Laravel\RedisShard\Metrics\HealthRegistrar;
use Laravel\RedisShard\Metrics\ShardStatusCheck;
use Laravel\RedisShard\Tests\TestCase;

class ShardStatusCheckTest extends TestCase
{
    public function test_check_payload_shape(): void
    {
        $this->seedShards();

        $payload = (new ShardStatusCheck())->run();

        $this->assertSame('ok', $payload['status']);
        $this->assertStringContainsString('2/2', $payload['shortSummary']);
        $this->assertArrayHasKey('shards', $payload['meta']);
    }

    public function test_check_reports_failed_when_all_down(): void
    {
        config()->set('redis_sharding.connections', []);
        $this->app->forgetInstance('shard.manager');

        $payload = (new ShardStatusCheck())->run();

        $this->assertSame('failed', $payload['status']);
    }

    public function test_health_registrar_is_noop_without_laravel_health(): void
    {
        // Laravel Health component is optional; calling register must not throw.
        HealthRegistrar::register();
        $this->assertTrue(true);
    }

    protected function seedShards(): void
    {
        $dir = __DIR__ . '/../../tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $connections = [];
        foreach (['shard1', 'shard2'] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name . '-check-' . uniqid('', true) . '.sqlite';
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
            \Illuminate\Support\Facades\DB::purge($name);
            \Illuminate\Support\Facades\Schema::connection($name)->create('check_probe', function ($table): void {
                $table->increments('id');
            });
        }

        $this->app->forgetInstance('shard.manager');
    }
}
