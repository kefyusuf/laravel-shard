<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Concerns\ScansRedisKeys;
use Laravel\RedisShard\Tests\TestCase;

class ScansRedisKeysTest extends TestCase
{
    public function test_it_prefers_scan_over_keys(): void
    {
        $redis = new class () {
            public int $scanCalls = 0;
            public int $keysCalls = 0;

            public function scan(&$cursor, array $options): array
            {
                $this->scanCalls++;

                if ((string) $cursor === '0') {
                    $cursor = '1';

                    return ['shard:users:1'];
                }

                $cursor = '0';

                return ['shard:users:2'];
            }

            public function keys(string $pattern): array
            {
                $this->keysCalls++;

                return ['shard:users:fallback'];
            }
        };

        $scanner = new class () {
            use ScansRedisKeys;

            public function collect($redis, string $pattern): array
            {
                return iterator_to_array($this->scanKeys($redis, $pattern), false);
            }
        };

        $keys = $scanner->collect($redis, 'shard:*');

        $this->assertSame(['shard:users:1', 'shard:users:2'], $keys);
        $this->assertGreaterThan(0, $redis->scanCalls);
        $this->assertSame(0, $redis->keysCalls);
    }

    public function test_it_falls_back_to_keys_when_scan_is_unavailable(): void
    {
        $redis = new class () {
            public int $keysCalls = 0;

            public function scan(&$cursor, array $options): array
            {
                throw new \RuntimeException('SCAN unavailable');
            }

            public function keys(string $pattern): array
            {
                $this->keysCalls++;

                return ['shard:users:legacy'];
            }
        };

        $scanner = new class () {
            use ScansRedisKeys;

            public function collect($redis, string $pattern): array
            {
                return iterator_to_array($this->scanKeys($redis, $pattern), false);
            }
        };

        $keys = $scanner->collect($redis, 'shard:*');

        $this->assertSame(['shard:users:legacy'], $keys);
        $this->assertSame(1, $redis->keysCalls);
    }
}
