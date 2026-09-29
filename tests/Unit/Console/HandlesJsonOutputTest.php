<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Illuminate\Console\Command;
use Laravel\RedisShard\Console\Concerns\HandlesJsonOutput;
use Laravel\RedisShard\Tests\TestCase;

class HandlesJsonOutputTest extends TestCase
{
    public function test_emit_json_outputs_pretty_printed_payload(): void
    {
        $command = new class () extends Command {
            use HandlesJsonOutput;

            public string $captured = '';

            public function handle(): int
            {
                return 0;
            }

            public function exposedEmitJson(array $payload): void
            {
                $this->emitJson($payload);
            }

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->captured = (string) $string;
            }
        };

        $command->exposedEmitJson(['summary' => ['status' => 'ok']]);

        $decoded = json_decode($command->captured, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['summary']['status'] ?? null);
    }

    public function test_make_error_payload_provides_standard_shape(): void
    {
        $command = new class () extends Command {
            use HandlesJsonOutput;

            public function handle(): int
            {
                return 0;
            }

            public function exposedMakeErrorPayload(string $error, array $summary = []): array
            {
                return $this->makeErrorPayload($error, $summary);
            }
        };

        $payload = $command->exposedMakeErrorPayload('boom', ['table' => 'users']);

        $this->assertSame('error', $payload['summary']['status']);
        $this->assertSame('users', $payload['summary']['table']);
        $this->assertSame('boom', $payload['error']);
    }
}
