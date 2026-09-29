<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Unit\Console;

use Illuminate\Console\Command;
use Laravel\RedisShard\Console\Concerns\ValidatesOutputFormat;
use Laravel\RedisShard\Tests\TestCase;

class ValidatesOutputFormatTest extends TestCase
{
    public function test_it_accepts_table_and_json_formats(): void
    {
        $command = new class () extends Command {
            use ValidatesOutputFormat;

            public function handle(): int
            {
                return 0;
            }

            public function exposedValidateOutputFormat(string $format): ?string
            {
                return $this->validateOutputFormat($format);
            }
        };

        $this->assertSame('table', $command->exposedValidateOutputFormat('table'));
        $this->assertSame('json', $command->exposedValidateOutputFormat('json'));
    }

    public function test_it_rejects_unsupported_format(): void
    {
        $command = new class () extends Command {
            use ValidatesOutputFormat;

            public function handle(): int
            {
                return 0;
            }

            public function exposedValidateOutputFormat(string $format): ?string
            {
                return $this->validateOutputFormat($format);
            }

            public function error($string, $verbosity = null): void
            {
                // no-op for unit test
            }
        };

        $this->assertNull($command->exposedValidateOutputFormat('xml'));
    }
}
