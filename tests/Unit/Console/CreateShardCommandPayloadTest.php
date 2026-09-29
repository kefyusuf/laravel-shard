<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Commands\CreateShardCommand;
use Laravel\RedisShard\Tests\TestCase;

class CreateShardCommandPayloadTest extends TestCase
{
    public function test_respond_emits_valid_json_payload_in_json_mode(): void
    {
        $command = new class () extends CreateShardCommand {
            public string $capturedLine = '';

            public function exposedRespond(int $exitCode, bool $jsonOutput, array $payload): int
            {
                return $this->respond($exitCode, $jsonOutput, $payload);
            }

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->capturedLine = (string) $string;
            }
        };

        $payload = [
            'summary' => [
                'status' => 'ok',
                'shard' => 'shard1',
                'created' => true,
            ],
        ];

        $exitCode = $command->exposedRespond(0, true, $payload);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($command->capturedLine, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['summary']['status'] ?? null);
    }

    public function test_build_success_payload_has_expected_schema(): void
    {
        $command = new class () extends CreateShardCommand {
            public function exposedBuildSuccessPayload(
                string $name,
                string $driver,
                string $database,
                bool $skippedMigrations
            ): array {
                return $this->buildSuccessPayload($name, $driver, $database, $skippedMigrations);
            }
        };

        $payload = $command->exposedBuildSuccessPayload('shard1', 'sqlite', ':memory:', true);

        $this->assertSame('ok', $payload['summary']['status']);
        $this->assertSame('shard1', $payload['summary']['shard']);
        $this->assertSame('sqlite', $payload['summary']['driver']);
        $this->assertSame(':memory:', $payload['summary']['database']);
        $this->assertTrue($payload['summary']['created']);
        $this->assertTrue($payload['summary']['skipped_migrations']);
    }

    public function test_build_error_payload_has_expected_schema(): void
    {
        $command = new class () extends CreateShardCommand {
            public function exposedBuildErrorPayload(string $error, array $summary = []): array
            {
                return $this->buildErrorPayload($error, $summary);
            }
        };

        $payload = $command->exposedBuildErrorPayload('Shard already exists.', ['shard' => 'shard1']);

        $this->assertSame('error', $payload['summary']['status']);
        $this->assertSame('shard1', $payload['summary']['shard']);
        $this->assertSame('Shard already exists.', $payload['error']);
    }

    public function test_invoke_sub_command_uses_call_silent_in_json_mode(): void
    {
        $command = new class () extends CreateShardCommand {
            public string $invokedMethod = '';

            public function exposedInvokeSubCommand(bool $jsonOutput, string $command, array $parameters = []): int
            {
                return $this->invokeSubCommand($jsonOutput, $command, $parameters);
            }

            public function call($command, array $arguments = []): int
            {
                $this->invokedMethod = 'call';

                return 0;
            }

            public function callSilent($command, array $arguments = []): int
            {
                $this->invokedMethod = 'callSilent';

                return 0;
            }
        };

        $command->exposedInvokeSubCommand(true, 'migrate');

        $this->assertSame('callSilent', $command->invokedMethod);
    }

    public function test_invoke_sub_command_uses_call_in_table_mode(): void
    {
        $command = new class () extends CreateShardCommand {
            public string $invokedMethod = '';

            public function exposedInvokeSubCommand(bool $jsonOutput, string $command, array $parameters = []): int
            {
                return $this->invokeSubCommand($jsonOutput, $command, $parameters);
            }

            public function call($command, array $arguments = []): int
            {
                $this->invokedMethod = 'call';

                return 0;
            }

            public function callSilent($command, array $arguments = []): int
            {
                $this->invokedMethod = 'callSilent';

                return 0;
            }
        };

        $command->exposedInvokeSubCommand(false, 'migrate');

        $this->assertSame('call', $command->invokedMethod);
    }
}
