<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Console\Concerns\ValidatesOutputFormat;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;

class CreateShardCommand extends Command
{
    use ValidatesOutputFormat;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:create
                             {name : The name of the shard}
                             {--driver=mysql : The database driver}
                             {--host=127.0.0.1 : The database host}
                             {--port=3306 : The database port}
                             {--database= : The database name (defaults to shard_name)}
                             {--username=root : The database username}
                             {--password= : The database password}
                             {--skip-migrate : Skip running migrations on the new shard}
                             {--format=table : Output format (table, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new database shard';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $name = $this->argument('name');
        $format = $this->validateOutputFormat((string) $this->option('format'));
        if ($format === null) {
            return 1;
        }

        $jsonOutput = $format === 'json';

        // Validate shard name
        if (empty($name)) {
            return $this->respond(
                1,
                $jsonOutput,
                $this->buildErrorPayload('Shard name cannot be empty.', [
                    'shard' => '',
                    'created' => false,
                ])
            );
        }

        // Validate required parameters
        $requiredOptions = ['host', 'port', 'username'];
        foreach ($requiredOptions as $option) {
            $value = $this->option($option);
            if ($value === null || $value === '') {
                return $this->respond(
                    1,
                    $jsonOutput,
                    $this->buildErrorPayload('All database connection parameters are required.', [
                        'shard' => $name,
                        'created' => false,
                    ])
                );
            }
        }

        $database = $this->option('database') ?: 'shard_' . $name;
        $driver = $this->option('driver');

        $config = [
            'driver' => $driver,
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'database' => $database,
            'username' => $this->option('username'),
            'password' => $this->option('password'),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];

        // Create the database if it doesn't exist
        try {
            $this->createDatabase($config);
        } catch (\Exception $e) {
            return $this->respond(
                1,
                $jsonOutput,
                $this->buildErrorPayload("Failed to create database: {$e->getMessage()}", [
                    'shard' => $name,
                    'created' => false,
                    'driver' => (string) $driver,
                ])
            );
        }

        // Register the shard
        if (ShardManager::createShard($name, $config)) {
            if (!$jsonOutput) {
                $this->info("Shard \"{$name}\" created successfully!");
            }

            $skipMigrate = (bool) $this->option('skip-migrate');
            if (!$skipMigrate) {
                // Run migrations on the new shard
                $migrateExitCode = $this->invokeSubCommand($jsonOutput, 'migrate', [
                    '--database' => $name,
                    '--path' => 'database/migrations',
                ]);

                if ($jsonOutput && $migrateExitCode !== 0) {
                    return $this->respond(
                        1,
                        $jsonOutput,
                        $this->buildErrorPayload('Shard created but migrations failed.', [
                            'shard' => $name,
                            'created' => true,
                            'ran_migrations' => true,
                        ])
                    );
                }
            }

            return $this->respond(
                0,
                $jsonOutput,
                $this->buildSuccessPayload($name, (string) $driver, (string) $database, $skipMigrate)
            );
        }

        return $this->respond(
            1,
            $jsonOutput,
            $this->buildErrorPayload("Shard \"{$name}\" already exists!", [
                'shard' => $name,
                'created' => false,
            ])
        );
    }

    /**
     * Create the database for the shard.
     *
     * @param array<string, mixed> $config
     * @return void
     */
    protected function createDatabase(array $config): void
    {
        if ($config['driver'] === 'sqlite') {
            $database = (string) $config['database'];
            if ($database !== ':memory:' && !file_exists($database)) {
                $directory = dirname($database);
                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                touch($database);
            }

            return;
        }

        // Create a temporary connection without specifying the database
        $tempConfig = $config;
        unset($tempConfig['database']);

        if ($config['driver'] === 'pgsql') {
            $tempConfig['database'] = 'postgres';
        }

        config(['database.connections.temp' => $tempConfig]);

        $db = DB::connection('temp');
        $databaseName = (string) $config['database'];

        switch ($config['driver']) {
            case 'mysql':
                $escapedDatabase = str_replace('`', '``', $databaseName);
                $db->statement("CREATE DATABASE IF NOT EXISTS `{$escapedDatabase}`");
                break;
            case 'pgsql':
                $escapedDatabase = str_replace('"', '""', $databaseName);
                $db->statement("CREATE DATABASE \"{$escapedDatabase}\"");
                break;
            case 'sqlsrv':
                $escapedDatabase = str_replace("'", "''", $databaseName);
                $escapedBracketDatabase = str_replace(']', ']]', $databaseName);
                $db->statement("IF DB_ID(N'{$escapedDatabase}') IS NULL CREATE DATABASE [{$escapedBracketDatabase}]");
                break;
            default:
                throw new \InvalidArgumentException("Unsupported driver for shard creation: {$config['driver']}");
        }

        // Close the temporary connection
        $db->disconnect();
    }

    /**
     * Build success payload for shard creation in JSON mode.
     */
    protected function buildSuccessPayload(string $name, string $driver, string $database, bool $skippedMigrations): array
    {
        return [
            'summary' => [
                'status' => 'ok',
                'shard' => $name,
                'driver' => $driver,
                'database' => $database,
                'created' => true,
                'skipped_migrations' => $skippedMigrations,
            ],
        ];
    }

    /**
     * Build error payload for shard creation in JSON mode.
     *
     * @param array<string, mixed> $summary
     */
    protected function buildErrorPayload(string $error, array $summary = []): array
    {
        return [
            'summary' => array_merge(['status' => 'error'], $summary),
            'error' => $error,
        ];
    }

    /**
     * Emit output according to selected mode and return exit code.
     */
    protected function respond(int $exitCode, bool $jsonOutput, array $payload): int
    {
        if ($jsonOutput) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));
        } elseif (isset($payload['error']) && is_string($payload['error'])) {
            $this->error($payload['error']);
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
