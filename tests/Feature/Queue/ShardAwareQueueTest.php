<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tests\Feature\Queue;

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\RedisShard\Exceptions\ShardingException;
use Laravel\RedisShard\Queue\RestoreShardContext;
use Laravel\RedisShard\Queue\ShardAwareJob;
use Laravel\RedisShard\Queue\ShardContext;
use Laravel\RedisShard\Queue\ShardContextDispatcher;
use Laravel\RedisShard\Tests\TestCase;
use Laravel\RedisShard\Traits\Shardable;

class ShardAwareQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('redis_sharding.modules.queue', true);
    }

    public function test_dispatched_job_carries_shard_context(): void
    {
        $model = $this->makeShardableUser();
        $context = ShardContextDispatcher::capture($model);

        $job = new RecordingShardJob($context);

        $this->assertSame($context->connection, $job->getShardContext()?->connection);
        $this->assertSame($context->table, $job->getShardContext()?->table);
        $this->assertSame($context->key, $job->getShardContext()?->key);

        Queue::fake();
        dispatch($job);
        Queue::assertPushed(RecordingShardJob::class, 1);
    }

    public function test_restore_middleware_applies_shard_connection_to_request_attributes(): void
    {
        $shardPath = $this->prepareShardDatabase('queue-restore');
        $this->configureConnection('shard1', $shardPath);

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);

        $context = new ShardContext('users', 42, 'shard1', 'id');
        $payload = ['shard_context' => $context->toArray()];

        $job = \Mockery::mock(QueueJob::class);
        $job->shouldReceive('payload')->andReturn($payload);

        $middleware = new RestoreShardContext();
        $ran = false;

        $middleware->handle($job, function ($handled) use (&$ran): void {
            $ran = true;
            $this->assertSame(
                'shard1',
                request()->attributes->get('shard_connection')
            );
        });

        $this->assertTrue($ran);
    }

    public function test_restore_middleware_rejects_unknown_connection(): void
    {
        config()->set('redis_sharding.connections', []);

        $context = new ShardContext('users', 1, 'missing-shard', 'id');
        $middleware = new RestoreShardContext();

        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('connection "missing-shard" is not configured');

        $middleware->apply($context);
    }

    public function test_shard_aware_job_runs_handler_on_restored_shard(): void
    {
        $shardPath = $this->prepareShardDatabase('queue-handler');
        $this->configureConnection('shard1', $shardPath);

        config()->set('redis_sharding.connections', [
            'shard1' => ['driver' => 'sqlite', 'database' => $shardPath, 'prefix' => ''],
        ]);

        DB::purge('shard1');
        Schema::connection('shard1')->dropIfExists('users');
        Schema::connection('shard1')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::connection('testing')->dropIfExists('users');
        Schema::connection('testing')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('name');
        });

        $job = new class(new ShardContext('users', 1, 'shard1', 'id')) extends ShardAwareJob {
            public ?string $seenConnection = null;

            public function __construct(ShardContext $context)
            {
                $this->setShardContext($context);
            }

            public function handle(): void
            {
                $this->requireShardContext();

                $model = new class extends Model {
                    use Shardable;

                    protected $table = 'users';
                    public $timestamps = false;
                    protected $fillable = ['name'];

                    public function getShardKeyName(): string
                    {
                        return 'id';
                    }
                };

                $model->setShardConnection('shard1');
                $model->create(['name' => 'queued-row']);
                $this->seenConnection = $model->getConnectionName();
            }
        };

        $job->handle();

        $this->assertSame('shard1', $job->seenConnection);
        $this->assertSame(
            'queued-row',
            DB::connection('shard1')->table('users')->where('name', 'queued-row')->value('name')
        );
        $this->assertSame(0, DB::connection('testing')->table('users')->where('name', 'queued-row')->count());
    }

    public function test_shard_aware_job_requires_context(): void
    {
        $job = new class extends ShardAwareJob {
            public function handle(): void
            {
                $this->requireShardContext();
            }
        };

        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('requires a shard context');

        $job->handle();
    }

    public function test_capture_context_from_shardable_model(): void
    {
        $model = $this->makeShardableUser();
        $context = ShardContextDispatcher::capture($model);

        $this->assertSame('users', $context->table);
        $this->assertSame('shard1', $context->connection);
        $this->assertSame('user@example.com', $context->key);
        $this->assertSame('email', $context->shardKey);
    }

    public function test_capture_context_rejects_non_shardable_model(): void
    {
        $model = new class extends Model {
            protected $table = 'users';
            public $timestamps = false;
        };

        $this->expectException(ShardingException::class);
        $this->expectExceptionMessage('model is not Shardable');

        ShardContextDispatcher::capture($model);
    }

    protected function makeShardableUser(): Model
    {
        return new class extends Model {
            use Shardable;

            protected $table = 'users';
            public $timestamps = false;
            protected $fillable = ['email', 'name'];

            protected $attributes = [
                'email' => 'user@example.com',
                'name' => 'Queue User',
            ];

            public function getShardKeyName(): string
            {
                return 'email';
            }

            public function getConnectionName(): ?string
            {
                return 'shard1';
            }
        };
    }

    protected function prepareShardDatabase(string $suffix): string
    {
        $dir = __DIR__ . '/../../tmp';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $shardPath = $dir . DIRECTORY_SEPARATOR . "queue-shard-{$suffix}.sqlite";

        if (file_exists($shardPath)) {
            unlink($shardPath);
        }

        touch($shardPath);

        return $shardPath;
    }

    protected function configureConnection(string $name, string $databasePath): void
    {
        config()->set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
        ]);
    }
}

/**
 * Simple job used only for payload assertions.
 */
class RecordingShardJob extends ShardAwareJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function __construct(?ShardContext $context = null)
    {
        $this->setShardContext($context);
    }

    public function handle(): void
    {
    }
}
