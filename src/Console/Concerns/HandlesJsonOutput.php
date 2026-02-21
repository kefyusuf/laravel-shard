<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Concerns;

trait HandlesJsonOutput
{
    /**
     * Emit JSON output in a consistent pretty-printed format.
     *
     * @param array<string, mixed> $payload
     */
    protected function emitJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));
    }

    /**
     * Build a standard error payload shape for JSON responses.
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    protected function makeErrorPayload(string $error, array $summary = []): array
    {
        return [
            'summary' => array_merge(['status' => 'error'], $summary),
            'error' => $error,
        ];
    }
}
