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
        $scanPatterns = [$pattern];
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        if ($redisPrefix !== '' && !str_starts_with($pattern, $redisPrefix)) {
            $scanPatterns[] = $redisPrefix . $pattern;
        }

        $seenKeys = [];
        $yieldedAny = false;

        foreach ($scanPatterns as $scanPattern) {
            $cursor = 0;
            $matchedAny = false;

            do {
                $keys = [];
                $nextCursor = 0;

                try {
                    $response = $redis->scan($cursor, [
                        'match' => $scanPattern,
                        'count' => $count,
                    ]);
                } catch (\Throwable $e) {
                    // Fallback to KEYS only when SCAN is unavailable in the runtime client.
                    foreach ((array) $redis->keys($scanPattern) as $key) {
                        $normalized = (string) $key;
                        if (isset($seenKeys[$normalized])) {
                            continue;
                        }

                        $seenKeys[$normalized] = true;
                        $yieldedAny = true;
                        yield $normalized;
                    }
                    return;
                }

                if ($response === false) {
                    break;
                }

                if (is_array($response) && isset($response[1]) && is_array($response[1])) {
                    // Predis style: [cursor, keys].
                    $nextCursor = $response[0];
                    $keys = $response[1];
                } elseif (is_array($response)) {
                    // PhpRedis style: keys with pass-by-reference cursor updates.
                    $nextCursor = $cursor;
                    $keys = $response;
                }

                foreach ($keys as $key) {
                    $matchedAny = true;
                    $normalized = (string) $key;

                    if (isset($seenKeys[$normalized])) {
                        continue;
                    }

                    $seenKeys[$normalized] = true;
                    $yieldedAny = true;
                    yield $normalized;
                }

                $cursor = is_numeric($nextCursor) ? (int) $nextCursor : (string) $nextCursor;
            } while ((string) $cursor !== '0');

            if ($matchedAny) {
                return;
            }
        }

        if ($yieldedAny) {
            return;
        }

        // Runtime clients can report empty SCAN results for prefixed keys in some environments.
        // Fallback to KEYS only when SCAN yielded nothing.
        foreach ($scanPatterns as $scanPattern) {
            foreach ((array) $redis->keys($scanPattern) as $key) {
                $normalized = (string) $key;
                if (isset($seenKeys[$normalized])) {
                    continue;
                }

                $seenKeys[$normalized] = true;
                yield $normalized;
            }
        }
    }
}
