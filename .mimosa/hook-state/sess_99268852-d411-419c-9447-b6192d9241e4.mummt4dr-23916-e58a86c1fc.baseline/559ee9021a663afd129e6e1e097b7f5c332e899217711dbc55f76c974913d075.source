<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature;

use Laravel\RedisShard\Tests\TestCase;

class BootConfigValidationLenientTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        config()->set('redis_sharding.strict_validation', false);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'bad' => ['database' => ':memory:'], // missing required field 'driver'
        ]);
    }

    public function test_boot_allows_invalid_config_in_testing_environment_when_lenient(): void
    {
        $this->assertNotNull($this->app->make('shard.manager'));
    }
}
