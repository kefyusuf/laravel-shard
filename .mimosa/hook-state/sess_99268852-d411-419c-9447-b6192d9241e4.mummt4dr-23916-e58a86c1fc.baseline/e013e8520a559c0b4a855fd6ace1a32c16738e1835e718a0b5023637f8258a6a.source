<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Support;

use Laravel\RedisShard\Support\ModuleRegistry;
use Laravel\RedisShard\Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    public function test_core_is_always_enabled(): void
    {
        $registry = new ModuleRegistry(['core' => false, 'redis' => false, 'queue' => false]);

        $this->assertTrue($registry->enabled(ModuleRegistry::CORE));
        $this->assertFalse($registry->redis());
        $this->assertFalse($registry->queue());
    }

    public function test_defaults_enable_redis_and_disable_queue(): void
    {
        $registry = new ModuleRegistry([]);

        $this->assertTrue($registry->redis());
        $this->assertFalse($registry->queue());
    }

    public function test_can_enable_queue(): void
    {
        $registry = new ModuleRegistry(['queue' => true, 'redis' => false]);

        $this->assertTrue($registry->queue());
        $this->assertFalse($registry->redis());
    }

    public function test_from_config_reads_modules_key(): void
    {
        config()->set('redis_sharding.modules', [
            'core' => true,
            'redis' => false,
            'queue' => true,
        ]);

        $registry = ModuleRegistry::fromConfig();

        $this->assertFalse($registry->redis());
        $this->assertTrue($registry->queue());
    }
}
