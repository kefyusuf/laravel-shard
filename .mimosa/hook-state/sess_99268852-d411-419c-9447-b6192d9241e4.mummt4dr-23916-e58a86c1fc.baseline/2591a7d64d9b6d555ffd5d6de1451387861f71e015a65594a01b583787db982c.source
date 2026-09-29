<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Concerns;

trait ValidatesOutputFormat
{
    /**
     * Validate output format and print a standard error when invalid.
     */
    protected function validateOutputFormat(string $format): ?string
    {
        if (in_array($format, ['table', 'json'], true)) {
            return $format;
        }

        $this->error('Unsupported format "' . $format . '". Allowed: table, json.');

        return null;
    }
}
