<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Models\ShardMetadata;

class CreateShardCommand extends Command
{
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
                             {--skip-migrate : Skip running migrations on the new shard}';

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

        // Validate shard name
        if (empty($name)) {
            $this->error('Shard name cannot be empty.');
            return 1;
        }

        // Validate required parameters
        $requiredOptions = ['host', 'port', 'username'];
        foreach ($requiredOptions as $option) {
            $value = $this->option($option);
            if ($value === null || $value === '') {
                $this->error('All database connection parameters are required.');
                return 1;
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
            $this->error("Failed to create database: {$e->getMessage()}");
            return 1;
        }

        // Register the shard
        if (ShardManager::createShard($name, $config)) {
            $this->info("Shard \"{$name}\" created successfully!");

            if (!$this->option('skip-migrate')) {
                // Run migrations on the new shard
                $this->call('migrate', [
                    '--database' => $name,
                    '--path' => 'database/migrations',
                ]);
            }

            return 0;
        }

        $this->error("Shard \"{$name}\" already exists!");
        return 1;
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
}
