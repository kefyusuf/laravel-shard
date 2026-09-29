<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature;

use Laravel\RedisShard\Exceptions\ConfigurationException;
use Laravel\RedisShard\Tests\TestCase;

class BootConfigValidationStrictTest extends TestCase
{
    private ?ConfigurationException $bootException = null;

    protected function setUp(): void
    {
        // The provider boots during parent::setUp(), so the rejection surfaces
        // there — PHPUnit's expectException() cannot observe it from the body.
        try {
            parent::setUp();
        } catch (ConfigurationException $exception) {
            $this->bootException = $exception;
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        config()->set('redis_sharding.strict_validation', true);
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'bad' => ['database' => ':memory:'], // missing required field 'driver'
        ]);
    }

    public function test_boot_rejects_invalid_config_in_testing_environment_when_strict(): void
    {
        self::assertNotNull($this->bootException, 'Expected the provider to reject the invalid config during boot.');
        $this->assertSame("Connection 'bad' is missing required field 'driver'", $this->bootException->getMessage());
    }
}
