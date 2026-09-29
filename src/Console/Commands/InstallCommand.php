<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Console\Concerns\HandlesJsonOutput;
use Laravel\RedisShard\Console\Concerns\ValidatesOutputFormat;

class InstallCommand extends Command
{
    use HandlesJsonOutput;
    use ValidatesOutputFormat;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis-shard:install
                            {--skip-migrate : Skip running package migrations}
                            {--format=table : Output format (table, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Redis Sharding package';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $format = $this->validateOutputFormat((string) $this->option('format'));
        if ($format === null) {
            return 1;
        }

        $jsonOutput = $format === 'json';
        $skipMigrate = (bool) $this->option('skip-migrate');

        if (! $jsonOutput) {
            $this->info('Installing Laravel Redis Sharding...');
            $this->info('Publishing configuration...');
        }

        $publishExitCode = $this->invokeSubCommand($jsonOutput, 'vendor:publish', [
            '--provider' => 'Laravel\\RedisShard\\RedisShardServiceProvider',
            '--tag' => 'config',
        ]);

        if ($publishExitCode !== 0) {
            return $this->respond(
                1,
                $jsonOutput,
                $this->buildPayload('error', false, false, $skipMigrate, 'Failed to publish configuration.')
            );
        }

        if (! $skipMigrate) {
            if (! $jsonOutput) {
                $this->info('Running migrations...');
            }

            $migrateExitCode = $this->invokeSubCommand($jsonOutput, 'migrate');
            if ($migrateExitCode !== 0) {
                return $this->respond(
                    1,
                    $jsonOutput,
                    $this->buildPayload('error', true, false, false, 'Failed to run migrations.')
                );
            }
        } else {
            if (! $jsonOutput) {
                $this->warn('Skipping migrations as requested.');
            }
        }

        if (! $jsonOutput) {
            $this->info('Laravel Redis Sharding installed successfully.');
        }

        return $this->respond(
            0,
            $jsonOutput,
            $this->buildPayload('ok', true, ! $skipMigrate, $skipMigrate)
        );
    }

    /**
     * Build install command payload.
     */
    protected function buildPayload(
        string $status,
        bool $publishedConfig,
        bool $ranMigrations,
        bool $skippedMigrations,
        ?string $error = null
    ): array {
        $payload = [
            'summary' => [
                'status' => $status,
                'published_config' => $publishedConfig,
                'ran_migrations' => $ranMigrations,
                'skipped_migrations' => $skippedMigrations,
            ],
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        return $payload;
    }

    /**
     * Emit output according to selected mode and return exit code.
     */
    protected function respond(int $exitCode, bool $jsonOutput, array $payload): int
    {
        if ($jsonOutput) {
            $this->emitJson($payload);
        }

        return $exitCode;
    }

    /**
     * Invoke sub-commands quietly in json mode to keep output parseable.
     *
     * @param array<string, mixed> $parameters
     */
    protected function invokeSubCommand(bool $jsonOutput, string $command, array $parameters = []): int
    {
        if ($jsonOutput) {
            return (int) $this->callSilent($command, $parameters);
        }

        return (int) $this->call($command, $parameters);
    }
}
