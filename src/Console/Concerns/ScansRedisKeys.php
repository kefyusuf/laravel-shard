<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Concerns;

trait ScansRedisKeys
{
    /**
     * Iterate redis keys using SCAN to avoid blocking the server.
     *
     * @return \Generator<int, string>
     */
    protected function scanKeys($redis, string $pattern, int $count = 500): \Generator
    {
        $cursor = '0';

        do {
            $keys = [];
            $nextCursor = '0';

            try {
                $response = $redis->scan($cursor, [
                    'match' => $pattern,
                    'count' => $count,
                ]);
            } catch (\Throwable $e) {
                // Fallback to KEYS only when SCAN is unavailable in the runtime client.
                foreach ((array) $redis->keys($pattern) as $key) {
                    yield (string) $key;
                }
                return;
            }

            if ($response === false) {
                return;
            }

            if (is_array($response) && isset($response[1]) && is_array($response[1])) {
                // Predis style: [cursor, keys].
                $nextCursor = (string) $response[0];
                $keys = $response[1];
            } elseif (is_array($response)) {
                // PhpRedis style: keys with pass-by-reference cursor updates.
                $nextCursor = (string) $cursor;
                $keys = $response;
            }

            foreach ($keys as $key) {
                yield (string) $key;
            }

            $cursor = $nextCursor;
        } while ($cursor !== '0');
    }
}
