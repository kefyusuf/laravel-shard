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
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = '{"summary":{"status":"error"},"error":"Failed to encode JSON output."}';
        }

        if ($this->output !== null) {
            $this->output->write($json . PHP_EOL);

            return;
        }

        $this->line($json);
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
