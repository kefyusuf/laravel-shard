<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Console\Concerns\HandlesJsonOutput;
use Laravel\RedisShard\Console\Concerns\ValidatesOutputFormat;
use Laravel\RedisShard\Metrics\ShardDiagnosticReport;

class ShardReportCommand extends Command
{
    use HandlesJsonOutput;
    use ValidatesOutputFormat;

    protected $signature = 'shard:report
                            {--format=table : Output format (table, json)}';

    protected $description = 'Print a comprehensive sharding diagnostics report';

    public function handle(ShardDiagnosticReport $report): int
    {
        $format = $this->validateOutputFormat((string) $this->option('format'));
        if ($format === null) {
            return 1;
        }

        $payload = $report->toArray();

        if ($format === 'json') {
            $this->emitJson($payload);

            return 0;
        }

        $this->info('Shard diagnostics');
        $this->line(sprintf(
            '  status: %s  strategy: %s  issues: %d',
            $payload['status'],
            $payload['summary']['strategy'] ?? 'none',
            $payload['summary']['issues']
        ));

        $this->newLine();
        $this->table(
            ['Shard', 'Status', 'Driver', 'Latency', 'Records', 'Meta'],
            collect($payload['shards'])->map(static function (array $shard) {
                return [
                    $shard['connection'],
                    $shard['status'],
                    $shard['driver'] ?? '-',
                    $shard['latency_ms'] === null ? '-' : $shard['latency_ms'] . ' ms',
                    $shard['metadata']['record_count'],
                    $shard['metadata']['status'],
                ];
            })->all()
        );

        $this->line(sprintf(
            '  distribution: total=%d ideal=%.1f balance=%s',
            $payload['distribution']['total_records'],
            $payload['distribution']['ideal_per_shard'],
            $payload['distribution']['balance_score'] ?? 'n/a'
        ));
        $this->line(sprintf(
            '  locator: %s',
            $payload['locator']['class'] ?? 'none'
        ));
        $this->line(sprintf(
            '  modules: core=%s redis=%s queue=%s',
            $payload['modules']['core'] ? 'on' : 'off',
            $payload['modules']['redis'] ? 'on' : 'off',
            $payload['modules']['queue'] ? 'on' : 'off'
        ));

        if ($payload['issues'] !== []) {
            $this->newLine();
            $this->warn('Issues:');
            foreach ($payload['issues'] as $issue) {
                $this->line('  - ' . $issue);
            }
        }

        return 0;
    }
}
