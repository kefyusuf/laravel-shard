<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Facades\ShardManager;
use Laravel\RedisShard\Tests\TestCase;

class CrossShardTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['shard1', 'shard2'] as $name) {
            config()->set("database.connections.{$name}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            DB::purge($name);
            Schema::connection($name)->dropIfExists('users');
            Schema::connection($name)->create('users', function ($table): void {
                $table->increments('id');
                $table->string('name');
            });
        }

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'shard2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        $this->app->forgetInstance('shard.manager');
        ShardManager::clearResolvedInstances();
    }

    public function test_transaction_commits_on_every_shard(): void
    {
        ShardManager::transaction(['shard1', 'shard2'], function (): void {
            DB::connection('shard1')->table('users')->insert(['id' => 1, 'name' => 'Alice']);
            DB::connection('shard2')->table('users')->insert(['id' => 1, 'name' => 'Bob']);
        });

        $this->assertSame(1, DB::connection('shard1')->table('users')->count());
        $this->assertSame(1, DB::connection('shard2')->table('users')->count());
    }

    public function test_transaction_returns_the_callback_result(): void
    {
        $result = ShardManager::transaction(['shard1'], fn (): string => 'value');

        $this->assertSame('value', $result);
    }

    public function test_transaction_rolls_back_every_shard_when_the_callback_throws(): void
    {
        try {
            ShardManager::transaction(['shard1', 'shard2'], function (): void {
                DB::connection('shard1')->table('users')->insert(['id' => 1, 'name' => 'Alice']);
                DB::connection('shard2')->table('users')->insert(['id' => 1, 'name' => 'Bob']);

                throw new \RuntimeException('business rule violated');
            });
            self::fail('Expected the callback exception to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('business rule violated', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection('shard1')->table('users')->count());
        $this->assertSame(0, DB::connection('shard2')->table('users')->count());
    }

    public function test_transaction_rolls_back_begun_shards_when_a_later_begin_fails(): void
    {
        try {
            ShardManager::transaction(['shard1', 'missing_connection'], function (): void {
                DB::connection('shard1')->table('users')->insert(['id' => 1, 'name' => 'Alice']);
            });
            self::fail('Expected the begin failure to surface as a ShardingException.');
        } catch (ShardingException $exception) {
            $this->assertStringContainsString('missing_connection', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection('shard1')->table('users')->count());
    }

    public function test_transaction_requires_at_least_one_shard(): void
    {
        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('at least one');

        ShardManager::transaction([], fn (): bool => true);
    }
}
