<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Support;

/**
 * Enabled sharding modules resolved from config.
 */
final class ModuleRegistry
{
    public const CORE = 'core';
    public const REDIS = 'redis';
    public const QUEUE = 'queue';

    public const DEFAULTS = [
        self::CORE => true,
        self::REDIS => true,
        self::QUEUE => false,
    ];

    public function __construct(
        protected array $enabled = self::DEFAULTS,
    ) {
        $this->enabled = array_merge(self::DEFAULTS, $enabled);
        // Core cannot be disabled.
        $this->enabled[self::CORE] = true;
    }

    public static function fromConfig(?array $modules = null): self
    {
        return new self($modules ?? (array) config('redis_sharding.modules', []));
    }

    public function enabled(string $module): bool
    {
        return (bool) ($this->enabled[$module] ?? false);
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->enabled;
    }

    public function redis(): bool
    {
        return $this->enabled(self::REDIS);
    }

    public function queue(): bool
    {
        return $this->enabled(self::QUEUE);
    }
}
