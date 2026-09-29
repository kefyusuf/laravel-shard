<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Testing;

use Illuminate\Support\Facades\DB;
use Laravel\RedisShard\Facades\ShardManager;

/**
 * Load Testing Example for Laravel Redis Sharding
 * 
 * This demonstrates how to perform load testing on your sharded setup.
 * Run: php examples/LoadTestingExample.php
 */
class LoadTestingExample
{
    private int $concurrentUsers = 100;
    private int $operationsPerUser = 100;
    private array $metrics = [];

    public function run(): void
    {
        echo "🔥 Load Testing Laravel Redis Sharding\n";
        echo str_repeat("=", 60) . "\n";
        echo "Concurrent Users: {$this->concurrentUsers}\n";
        echo "Operations per User: {$this->operationsPerUser}\n";
        echo "Total Operations: " . ($this->concurrentUsers * $this->operationsPerUser) . "\n\n";

        $this->testConcurrentWrites();
        $this->testConcurrentReads();
        $this->testMixedWorkload();
        $this->testShardAddition();
        
        $this->displaySummary();
    }

    private function testConcurrentWrites(): void
    {
        echo "📝 Testing Concurrent Writes...\n";
        
        $start = microtime(true);
        $operations = 0;
        $errors = 0;

        for ($user = 1; $user <= $this->concurrentUsers; $user++) {
            for ($op = 1; $op <= $this->operationsPerUser; $op++) {
                try {
                    $userId = ($user * 1000) + $op;
                    $shard = ShardManager::getShardConnection('users', $userId);
                    
                    // Simulate write operation
                    DB::connection($shard)->table('users')->insert([
                        'id' => $userId,
                        'name' => "User {$userId}",
                        'email' => "user{$userId}@example.com",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    $operations++;
                } catch (\Exception $e) {
                    $errors++;
                }
            }
        }

        $duration = microtime(true) - $start;
        $this->metrics['writes'] = [
            'operations' => $operations,
            'errors' => $errors,
            'duration' => $duration,
            'ops_per_second' => $operations / $duration,
        ];

        echo "  ✅ Completed: {$operations} writes\n";
        echo "  ❌ Errors: {$errors}\n";
        echo "  ⏱️  Duration: " . round($duration, 2) . "s\n";
        echo "  🚀 Throughput: " . round($operations / $duration, 2) . " ops/sec\n\n";
    }

    private function testConcurrentReads(): void
    {
        echo "📖 Testing Concurrent Reads...\n";
        
        $start = microtime(true);
        $operations = 0;
        $errors = 0;

        for ($user = 1; $user <= $this->concurrentUsers; $user++) {
            for ($op = 1; $op <= $this->operationsPerUser; $op++) {
                try {
                    $userId = ($user * 1000) + $op;
                    $shard = ShardManager::getShardConnection('users', $userId);
                    
                    // Simulate read operation
                    DB::connection($shard)->table('users')
                        ->where('id', $userId)
                        ->first();
                    
                    $operations++;
                } catch (\Exception $e) {
                    $errors++;
                }
            }
        }

        $duration = microtime(true) - $start;
        $this->metrics['reads'] = [
            'operations' => $operations,
            'errors' => $errors,
            'duration' => $duration,
            'ops_per_second' => $operations / $duration,
        ];

        echo "  ✅ Completed: {$operations} reads\n";
        echo "  ❌ Errors: {$errors}\n";
        echo "  ⏱️  Duration: " . round($duration, 2) . "s\n";
        echo "  🚀 Throughput: " . round($operations / $duration, 2) . " ops/sec\n\n";
    }

    private function testMixedWorkload(): void
    {
        echo "🔄 Testing Mixed Workload (70% reads, 30% writes)...\n";
        
        $start = microtime(true);
        $reads = 0;
        $writes = 0;
        $errors = 0;

        for ($user = 1; $user <= $this->concurrentUsers; $user++) {
            for ($op = 1; $op <= $this->operationsPerUser; $op++) {
                try {
                    $userId = ($user * 1000) + $op;
                    $shard = ShardManager::getShardConnection('users', $userId);
                    
                    // 70% reads, 30% writes
                    if (rand(1, 100) <= 70) {
                        DB::connection($shard)->table('users')
                            ->where('id', $userId)
                            ->first();
                        $reads++;
                    } else {
                        DB::connection($shard)->table('users')
                            ->where('id', $userId)
                            ->update(['updated_at' => now()]);
                        $writes++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                }
            }
        }

        $duration = microtime(true) - $start;
        $total = $reads + $writes;
        
        $this->metrics['mixed'] = [
            'reads' => $reads,
            'writes' => $writes,
            'errors' => $errors,
            'duration' => $duration,
            'ops_per_second' => $total / $duration,
        ];

        echo "  📖 Reads: {$reads}\n";
        echo "  📝 Writes: {$writes}\n";
        echo "  ❌ Errors: {$errors}\n";
        echo "  ⏱️  Duration: " . round($duration, 2) . "s\n";
        echo "  🚀 Throughput: " . round($total / $duration, 2) . " ops/sec\n\n";
    }

    private function testShardAddition(): void
    {
        echo "➕ Testing Shard Addition Impact...\n";
        
        // Measure before adding shard
        $beforeShards = count(ShardManager::getAvailableShards());
        $start = microtime(true);
        
        $operations = 0;
        for ($i = 1; $i <= 1000; $i++) {
            $shard = ShardManager::getShardConnection('users', $i);
            $operations++;
        }
        
        $beforeTime = microtime(true) - $start;

        // Add new shard
        ShardManager::createShard('shard_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'test_',
        ]);

        // Measure after adding shard
        $afterShards = count(ShardManager::getAvailableShards());
        $start = microtime(true);
        
        $operations = 0;
        for ($i = 1001; $i <= 2000; $i++) {
            $shard = ShardManager::getShardConnection('users', $i);
            $operations++;
        }
        
        $afterTime = microtime(true) - $start;

        echo "  📊 Before: {$beforeShards} shards, " . round($beforeTime * 1000, 2) . "ms\n";
        echo "  📊 After:  {$afterShards} shards, " . round($afterTime * 1000, 2) . "ms\n";
        echo "  📈 Impact: " . round((($afterTime - $beforeTime) / $beforeTime) * 100, 2) . "%\n\n";
    }

    private function displaySummary(): void
    {
        echo str_repeat("=", 60) . "\n";
        echo "📊 LOAD TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n\n";

        echo "Write Performance:\n";
        echo "  {$this->metrics['writes']['operations']} ops in {$this->metrics['writes']['duration']}s\n";
        echo "  " . round($this->metrics['writes']['ops_per_second'], 2) . " ops/sec\n\n";

        echo "Read Performance:\n";
        echo "  {$this->metrics['reads']['operations']} ops in {$this->metrics['reads']['duration']}s\n";
        echo "  " . round($this->metrics['reads']['ops_per_second'], 2) . " ops/sec\n\n";

        echo "Mixed Workload:\n";
        echo "  {$this->metrics['mixed']['reads']} reads + {$this->metrics['mixed']['writes']} writes\n";
        echo "  " . round($this->metrics['mixed']['ops_per_second'], 2) . " ops/sec\n\n";

        echo "💡 Recommendations:\n";
        if ($this->metrics['writes']['ops_per_second'] < 1000) {
            echo "  ⚠️  Consider connection pooling for better write performance\n";
        }
        if ($this->metrics['reads']['ops_per_second'] < 5000) {
            echo "  ⚠️  Consider implementing caching for read-heavy workloads\n";
        }
        echo "  ✅ Monitor shard distribution balance regularly\n";
        echo "  ✅ Plan shard additions during low-traffic periods\n";
    }
}

// Run if executed directly
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'LoadTestingExample.php') {
    require __DIR__ . '/../vendor/autoload.php';
    
    $loadTest = new LoadTestingExample();
    $loadTest->run();
}
