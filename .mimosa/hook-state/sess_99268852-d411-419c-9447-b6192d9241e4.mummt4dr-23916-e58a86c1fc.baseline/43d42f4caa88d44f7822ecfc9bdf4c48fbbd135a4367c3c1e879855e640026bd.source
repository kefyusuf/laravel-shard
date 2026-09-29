<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Benchmarks;

use Laravel\RedisShard\Strategies\ConsistentHashingStrategy;
use Laravel\RedisShard\Strategies\ModuloStrategy;
use Laravel\RedisShard\Strategies\RangeBasedStrategy;

/**
 * Performance benchmark for sharding strategies
 * 
 * Run: php benchmarks/ShardingStrategyBenchmark.php
 */
class ShardingStrategyBenchmark
{
    private array $shards = ['shard1', 'shard2', 'shard3', 'shard4', 'shard5'];
    private int $iterations = 10000;

    public function run(): void
    {
        echo "🔬 Sharding Strategy Performance Benchmark\n";
        echo str_repeat("=", 60) . "\n";
        echo "Iterations: " . number_format($this->iterations) . "\n";
        echo "Shards: " . count($this->shards) . "\n\n";

        $strategies = [
            'Modulo' => new ModuloStrategy(),
            'Consistent Hashing' => new ConsistentHashingStrategy(),
            'Range-Based' => new RangeBasedStrategy(),
        ];

        $results = [];

        foreach ($strategies as $name => $strategy) {
            echo "Testing {$name} Strategy...\n";
            $results[$name] = $this->benchmarkStrategy($strategy, $name);
            echo "\n";
        }

        $this->displayComparison($results);
    }

    private function benchmarkStrategy(object $strategy, string $name): array
    {
        // Test numeric keys
        $numericStart = microtime(true);
        $numericDistribution = [];
        
        for ($i = 1; $i <= $this->iterations; $i++) {
            $shard = $strategy->determine('users', $i, $this->shards);
            $numericDistribution[$shard] = ($numericDistribution[$shard] ?? 0) + 1;
        }
        
        $numericTime = microtime(true) - $numericStart;

        // Test string keys
        $stringStart = microtime(true);
        $stringDistribution = [];
        
        for ($i = 1; $i <= $this->iterations; $i++) {
            $key = "user{$i}@example.com";
            $shard = $strategy->determine('users', $key, $this->shards);
            $stringDistribution[$shard] = ($stringDistribution[$shard] ?? 0) + 1;
        }
        
        $stringTime = microtime(true) - $stringStart;

        // Calculate distribution metrics
        $avgPerShard = $this->iterations / count($this->shards);
        $variance = $this->calculateVariance($numericDistribution, $avgPerShard);
        $balanceScore = 100 - (($variance / $avgPerShard) * 100);

        $result = [
            'numeric_time' => $numericTime,
            'string_time' => $stringTime,
            'avg_time' => ($numericTime + $stringTime) / 2,
            'ops_per_second' => (int)($this->iterations / (($numericTime + $stringTime) / 2)),
            'distribution' => $numericDistribution,
            'balance_score' => round($balanceScore, 2),
            'variance' => round($variance, 2),
        ];

        $this->displayResult($name, $result);

        return $result;
    }

    private function calculateVariance(array $distribution, float $average): float
    {
        $sum = 0;
        foreach ($distribution as $count) {
            $sum += pow($count - $average, 2);
        }
        
        return sqrt($sum / count($distribution));
    }

    private function displayResult(string $name, array $result): void
    {
        echo "  ⏱️  Numeric Keys: " . round($result['numeric_time'] * 1000, 2) . "ms\n";
        echo "  ⏱️  String Keys:  " . round($result['string_time'] * 1000, 2) . "ms\n";
        echo "  🚀 Operations/sec: " . number_format($result['ops_per_second']) . "\n";
        echo "  ⚖️  Balance Score: {$result['balance_score']}%\n";
        echo "  📊 Distribution:\n";
        
        foreach ($result['distribution'] as $shard => $count) {
            $percentage = ($count / $this->iterations) * 100;
            $bar = str_repeat("█", (int)($percentage / 2));
            echo "     {$shard}: {$count} ({$percentage}%) {$bar}\n";
        }
    }

    private function displayComparison(array $results): void
    {
        echo str_repeat("=", 60) . "\n";
        echo "📊 PERFORMANCE COMPARISON\n";
        echo str_repeat("=", 60) . "\n\n";

        // Find fastest
        $fastest = null;
        $fastestTime = PHP_FLOAT_MAX;
        foreach ($results as $name => $result) {
            if ($result['avg_time'] < $fastestTime) {
                $fastestTime = $result['avg_time'];
                $fastest = $name;
            }
        }

        // Display comparison
        foreach ($results as $name => $result) {
            $relative = ($result['avg_time'] / $fastestTime);
            $slower = $relative > 1 ? sprintf("(%.2fx slower)", $relative) : "(fastest)";
            
            echo "{$name}:\n";
            echo "  Speed: " . number_format($result['ops_per_second']) . " ops/sec {$slower}\n";
            echo "  Balance: {$result['balance_score']}%\n";
            echo "\n";
        }

        // Recommendations
        echo "💡 RECOMMENDATIONS:\n";
        echo str_repeat("-", 60) . "\n";
        echo "⚡ Fastest: {$fastest}\n";
        
        $bestBalance = null;
        $bestBalanceScore = 0;
        foreach ($results as $name => $result) {
            if ($result['balance_score'] > $bestBalanceScore) {
                $bestBalanceScore = $result['balance_score'];
                $bestBalance = $name;
            }
        }
        echo "⚖️  Best Balance: {$bestBalance} ({$bestBalanceScore}%)\n";
        
        echo "\n";
        echo "📝 Use Modulo for: Simple numeric IDs with even distribution\n";
        echo "📝 Use Consistent Hashing for: Dynamic shard scaling\n";
        echo "📝 Use Range-Based for: Time-series or sequential data\n";
    }
}

// Run benchmark if executed directly
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'ShardingStrategyBenchmark.php') {
    require __DIR__ . '/../vendor/autoload.php';
    
    $benchmark = new ShardingStrategyBenchmark();
    $benchmark->run();
}
