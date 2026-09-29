<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Tests\TestCase;

/**
 * Pins the JSON payload shapes of shard:status (previously asserted against
 * private build*Payload methods; now asserted through the command interface).
 */
class ShardStatusJsonPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMockingConsoleOutput();
        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $this->app->forgetInstance('shard.manager');
        Facade::clearResolvedInstance('shard.manager');

        $locator = new class () implements ShardLocatorInterface {
            /** @var array<string, array<string, string>> */
            public array $keys = [
                'users' => ['a' => 'shard1', 'b' => 'shard1', 'c' => 'shard2', 'd' => 'shard2', 'e' => 'shard2'],
                'orders' => ['o1' => 'shard1', 'o2' => 'shard2'],
            ];

            public function locate(string $table, mixed $key): ?string
            {
                return $this->keys[$table][(string) $key] ?? null;
            }

            public function register(string $table, mixed $key, string $shardConnection): bool
            {
                $this->keys[$table][(string) $key] = $shardConnection;

                return true;
            }

            public function forget(string $table, mixed $key): bool
            {
                return true;
            }

            public function getKeysForShard(string $table, string $shardConnection): array
            {
                $keys = [];
                foreach (($this->keys[$table] ?? []) as $key => $shard) {
                    if ($shard === $shardConnection) {
                        $keys[] = $key;
                    }
                }

                return $keys;
            }
        };

        $this->app->instance(ShardLocatorInterface::class, $locator);
        $this->app->instance('shard.locator', $locator);
    }

    public function test_overall_json_payload_embeds_summary_and_shards(): void
    {
        $exitCode = Artisan::call('shard:status', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $payload['summary']['status']);
        $this->assertSame(2, $payload['summary']['total_shards']);
        $this->assertArrayHasKey('active_strategies', $payload['summary']);
        $this->assertArrayHasKey('default_strategy', $payload['summary']);
        $this->assertCount(2, $payload['shards']);
        foreach ($payload['shards'] as $shard) {
            $this->assertArrayHasKey('name', $shard);
            $this->assertArrayHasKey('status', $shard);
            $this->assertArrayHasKey('records', $shard);
            $this->assertArrayHasKey('last_rebalanced', $shard);
            $this->assertArrayHasKey('created', $shard);
        }
    }

    public function test_table_json_payload_summary_has_expected_fields(): void
    {
        $exitCode = Artisan::call('shard:status', ['--table' => 'users', '--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $payload['summary']['status']);
        $this->assertSame('users', $payload['summary']['table']);
        $this->assertSame(5, $payload['summary']['total_keys']);
        $this->assertSame(2, $payload['summary']['total_shards']);
        $this->assertCount(2, $payload['shards']);
    }

    public function test_shard_json_payload_summary_has_expected_fields(): void
    {
        $exitCode = Artisan::call('shard:status', ['--shard' => 'shard1', '--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $payload['summary']['status']);
        $this->assertSame('shard1', $payload['summary']['shard']);
        $this->assertSame('unknown', $payload['summary']['shard_status']);
        $this->assertSame(0, $payload['summary']['record_count']);
        $this->assertSame(2, $payload['summary']['table_count']);
        $this->assertArrayHasKey('shard', $payload);
        $this->assertArrayHasKey('tables', $payload);
    }

    public function test_unknown_shard_json_payload_is_an_error_with_context(): void
    {
        $exitCode = Artisan::call('shard:status', ['--shard' => 'shard9', '--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('error', $payload['summary']['status']);
        $this->assertSame('shard9', $payload['summary']['shard']);
        $this->assertSame("Shard 'shard9' not found.", $payload['error']);
    }
}
