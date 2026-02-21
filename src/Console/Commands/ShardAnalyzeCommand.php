<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Console\Commands;

use Illuminate\Console\Command;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Laravel\RedisShard\Facades\ShardManager;

class ShardAnalyzeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shard:analyze
                            {table? : Analyze specific table}
                            {--strategy= : Test with specific strategy}
                            {--sample-size=1000 : Number of sample keys to test}
                            {--format=table : Output format (table, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze shard distribution and performance';

    /**
     * Execute the console command.
     *
     * @param ShardLocatorInterface $locator
     * @return int
     */
    public function handle(ShardLocatorInterface $locator): int
    {
        $table = $this->argument('table');
        $strategy = $this->option('strategy');
        $sampleSize = (int) $this->option('sample-size');
        $format = $this->option('format');

        if ($table) {
            return $this->analyzeTable($table, $locator, $format);
        }

        if ($sampleSize < 2) {
            $this->error('Sample size must be at least 2.');
            return 1;
        }

        return $this->analyzeOverall($locator, $strategy, $sampleSize, $format);
    }

    /**
     * Analyze overall shard performance.
     *
     * @param ShardLocatorInterface $locator
     * @param string|null $strategy
     * @param int $sampleSize
     * @param string $format
     * @return int
     */
    protected function analyzeOverall(ShardLocatorInterface $locator, ?string $strategy, int $sampleSize, string $format): int
    {
        $this->info('Analyzing shard distribution...');
        
        $shards = ShardManager::getAvailableShards();
        if (empty($shards)) {
            $this->error('No available shards found.');
            return 1;
        }

        $strategies = $strategy ? [$strategy] : ShardManager::strategies()->keys()->toArray();
        
        $results = [];
        
        foreach ($strategies as $strategyName) {
            $this->line("Testing strategy: {$strategyName}");
            
            try {
                $strategyInstance = ShardManager::strategy($strategyName);
                $distribution = $this->testDistribution($strategyInstance, $shards, $sampleSize);
                
                $results[$strategyName] = [
                    'distribution' => $distribution,
                    'balance_score' => $this->calculateBalanceScore($distribution),
                    'std_deviation' => $this->calculateStandardDeviation($distribution),
                ];
            } catch (\Exception $e) {
                $this->error("Failed to test strategy {$strategyName}: {$e->getMessage()}");
                continue;
            }
        }
        
        if ($format === 'json') {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
        } else {
            $this->displayAnalysisResults($results, $sampleSize);
        }
        
        return 0;
    }

    /**
     * Analyze specific table distribution.
     *
     * @param string $table
     * @param ShardLocatorInterface $locator
     * @param string $format
     * @return int
     */
    protected function analyzeTable(string $table, ShardLocatorInterface $locator, string $format): int
    {
        $this->info("Analyzing table: {$table}");
        
        $shards = ShardManager::getAvailableShards();
        if (empty($shards)) {
            $this->error('No available shards found.');
            return 1;
        }

        $distribution = [];
        $totalKeys = 0;
        
        foreach ($shards as $shardName) {
            $keys = $locator->getKeysForShard($table, $shardName);
            $keyCount = count($keys);
            $distribution[$shardName] = $keyCount;
            $totalKeys += $keyCount;
        }
        
        if ($totalKeys === 0) {
            $this->warn("No keys found for table '{$table}'");
            return 0;
        }
        
        $analysis = [
            'table' => $table,
            'total_keys' => $totalKeys,
            'shard_count' => count($shards),
            'distribution' => $distribution,
            'balance_score' => $this->calculateBalanceScore($distribution),
            'std_deviation' => $this->calculateStandardDeviation($distribution),
            'recommendations' => $this->generateRecommendations($distribution, $totalKeys),
        ];
        
        if ($format === 'json') {
            $this->line(json_encode($analysis, JSON_PRETTY_PRINT));
        } else {
            $this->displayTableAnalysis($analysis);
        }
        
        return 0;
    }

    /**
     * Test distribution for a strategy.
     *
     * @param \Laravel\RedisShard\Contracts\ShardStrategyInterface $strategy
     * @param array $shards
     * @param int $sampleSize
     * @return array
     */
    protected function testDistribution($strategy, array $shards, int $sampleSize): array
    {
        $distribution = array_fill_keys($shards, 0);
        $numericSamples = intdiv($sampleSize, 2);
        $stringSamples = $sampleSize - $numericSamples;
        
        // Test with numeric keys
        for ($i = 1; $i <= $numericSamples; $i++) {
            $shard = $strategy->determine('test_table', $i, $shards);
            $distribution[$shard]++;
        }
        
        // Test with string keys
        for ($i = 1; $i <= $stringSamples; $i++) {
            $key = "user_{$i}@example.com";
            $shard = $strategy->determine('test_table', $key, $shards);
            $distribution[$shard]++;
        }
        
        return $distribution;
    }

    /**
     * Calculate balance score (0-100, higher is better).
     *
     * @param array $distribution
     * @return float
     */
    protected function calculateBalanceScore(array $distribution): float
    {
        $total = array_sum($distribution);
        if ($total === 0) {
            return 100.0;
        }
        
        $ideal = $total / count($distribution);
        $maxDeviation = 0;
        
        foreach ($distribution as $count) {
            $deviation = abs($count - $ideal) / $ideal;
            $maxDeviation = max($maxDeviation, $deviation);
        }
        
        return max(0, 100 - ($maxDeviation * 100));
    }

    /**
     * Calculate standard deviation of distribution.
     *
     * @param array $distribution
     * @return float
     */
    protected function calculateStandardDeviation(array $distribution): float
    {
        $count = count($distribution);
        if ($count === 0) {
            return 0.0;
        }
        
        $mean = array_sum($distribution) / $count;
        $variance = 0;
        
        foreach ($distribution as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        return sqrt($variance / $count);
    }

    /**
     * Generate recommendations based on distribution.
     *
     * @param array $distribution
     * @param int $totalKeys
     * @return array
     */
    protected function generateRecommendations(array $distribution, int $totalKeys): array
    {
        $recommendations = [];
        $ideal = $totalKeys / count($distribution);
        $threshold = $ideal * 0.2; // 20% threshold
        
        foreach ($distribution as $shard => $count) {
            $deviation = abs($count - $ideal);
            if ($deviation > $threshold) {
                $percentage = round(($deviation / $ideal) * 100, 2);
                if ($count > $ideal) {
                    $recommendations[] = "Shard '{$shard}' is overloaded by {$percentage}% - consider rebalancing";
                } else {
                    $recommendations[] = "Shard '{$shard}' is underutilized by {$percentage}% - consider rebalancing";
                }
            }
        }
        
        if (empty($recommendations)) {
            $recommendations[] = "Distribution looks good - no immediate action needed";
        }
        
        return $recommendations;
    }

    /**
     * Display analysis results.
     *
     * @param array $results
     * @param int $sampleSize
     * @return void
     */
    protected function displayAnalysisResults(array $results, int $sampleSize): void
    {
        $this->info("Strategy Performance Analysis (Sample Size: {$sampleSize})");
        $this->line('');
        
        $tableData = [];
        foreach ($results as $strategy => $data) {
            $tableData[] = [
                'Strategy' => $strategy,
                'Balance Score' => round($data['balance_score'], 2) . '%',
                'Std Deviation' => round($data['std_deviation'], 2),
                'Min Keys' => min($data['distribution']),
                'Max Keys' => max($data['distribution']),
            ];
        }
        
        $this->table(['Strategy', 'Balance Score', 'Std Deviation', 'Min Keys', 'Max Keys'], $tableData);
        
        // Show detailed distribution for each strategy
        foreach ($results as $strategy => $data) {
            $this->line('');
            $this->info("Distribution for {$strategy}:");
            $distData = [];
            foreach ($data['distribution'] as $shard => $count) {
                $percentage = round(($count / $sampleSize) * 100, 2);
                $distData[] = [
                    'Shard' => $shard,
                    'Keys' => $count,
                    'Percentage' => $percentage . '%',
                ];
            }
            $this->table(['Shard', 'Keys', 'Percentage'], $distData);
        }
    }

    /**
     * Display table analysis.
     *
     * @param array $analysis
     * @return void
     */
    protected function displayTableAnalysis(array $analysis): void
    {
        $this->info("Table Analysis: {$analysis['table']}");
        $this->info("Total Keys: {$analysis['total_keys']}");
        $this->info("Balance Score: " . round($analysis['balance_score'], 2) . '%');
        $this->info("Standard Deviation: " . round($analysis['std_deviation'], 2));
        $this->line('');
        
        $tableData = [];
        foreach ($analysis['distribution'] as $shard => $count) {
            $percentage = round(($count / $analysis['total_keys']) * 100, 2);
            $tableData[] = [
                'Shard' => $shard,
                'Keys' => $count,
                'Percentage' => $percentage . '%',
            ];
        }
        
        $this->table(['Shard', 'Keys', 'Percentage'], $tableData);
        
        $this->line('');
        $this->info('Recommendations:');
        foreach ($analysis['recommendations'] as $recommendation) {
            $this->line("• {$recommendation}");
        }
    }
}
