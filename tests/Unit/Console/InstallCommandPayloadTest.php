<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Laravel\RedisShard\Console\Commands\InstallCommand;
use Laravel\RedisShard\Tests\TestCase;

class InstallCommandPayloadTest extends TestCase
{
    public function test_invoke_sub_command_uses_call_silent_in_json_mode(): void
    {
        $command = new class extends InstallCommand {
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
        $command = new class extends InstallCommand {
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

    public function test_build_payload_for_successful_install(): void
    {
        $command = new class extends InstallCommand {
            public function exposedBuildPayload(
                string $status,
                bool $publishedConfig,
                bool $ranMigrations,
                bool $skippedMigrations,
                ?string $error = null
            ): array {
                return $this->buildPayload(
                    $status,
                    $publishedConfig,
                    $ranMigrations,
                    $skippedMigrations,
                    $error
                );
            }
        };

        $payload = $command->exposedBuildPayload('ok', true, true, false);

        $this->assertSame('ok', $payload['summary']['status']);
        $this->assertTrue($payload['summary']['published_config']);
        $this->assertTrue($payload['summary']['ran_migrations']);
        $this->assertFalse($payload['summary']['skipped_migrations']);
        $this->assertArrayNotHasKey('error', $payload);
    }

    public function test_build_payload_includes_error_when_present(): void
    {
        $command = new class extends InstallCommand {
            public function exposedBuildPayload(
                string $status,
                bool $publishedConfig,
                bool $ranMigrations,
                bool $skippedMigrations,
                ?string $error = null
            ): array {
                return $this->buildPayload(
                    $status,
                    $publishedConfig,
                    $ranMigrations,
                    $skippedMigrations,
                    $error
                );
            }
        };

        $payload = $command->exposedBuildPayload('error', true, false, false, 'Failed to run migrations.');

        $this->assertSame('error', $payload['summary']['status']);
        $this->assertSame('Failed to run migrations.', $payload['error']);
    }
}
