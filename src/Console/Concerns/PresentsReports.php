<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Concerns;

/**
 * Renders a report built once by a command, as table or JSON.
 *
 * The command gathers its data a single time into a plain report array and
 * hands it here; the presenter decides the output format. This keeps the
 * JSON shape and the table output derived from one data build, so they can
 * never drift apart.
 */
trait PresentsReports
{
    /**
     * @param array{
     *     json: array<string, mixed>,
     *     headers?: list<string>,
     *     rows?: list<array<int|string, mixed>>,
     *     header?: list<string>,
     *     meta?: list<string>
     * } $report
     */
    protected function renderReport(array $report): void
    {
        if ($this->isJsonOutput()) {
            $this->emitJson($report['json']);

            return;
        }

        foreach ($report['header'] ?? [] as $line) {
            $this->info($line);
        }

        if (isset($report['headers'], $report['rows']) && $report['rows'] !== []) {
            $this->table($report['headers'], $report['rows']);
        }

        foreach ($report['meta'] ?? [] as $line) {
            $this->info($line);
        }
    }

    /**
     * Commands either track JSON mode in a $jsonOutput property (validated
     * format) or fall back to reading the --format option directly.
     */
    protected function isJsonOutput(): bool
    {
        if (property_exists($this, 'jsonOutput')) {
            return (bool) $this->jsonOutput;
        }

        return $this->option('format') === 'json';
    }
}
