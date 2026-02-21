<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis-shard:install
                            {--skip-migrate : Skip running package migrations}';

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
        $this->info('Installing Laravel Redis Sharding...');

        $this->info('Publishing configuration...');
        $this->call('vendor:publish', [
            '--provider' => 'Laravel\\RedisShard\\RedisShardServiceProvider',
            '--tag' => 'config',
        ]);

        if (!$this->option('skip-migrate')) {
            $this->info('Running migrations...');
            $this->call('migrate');
        } else {
            $this->warn('Skipping migrations as requested.');
        }

        $this->info('Laravel Redis Sharding installed successfully.');

        return 0;
    }
}
