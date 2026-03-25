# Performance Benchmarks

This directory contains performance benchmarks for the Laravel Redis Sharding package.

## Running Benchmarks

```bash
# Run sharding strategy benchmark
php benchmarks/ShardingStrategyBenchmark.php
```

## Available Benchmarks

### Sharding Strategy Benchmark
Compares the performance and distribution quality of all three sharding strategies:
- **Modulo Strategy**: Simple modulo-based distribution
- **Consistent Hashing Strategy**: Minimal data movement when scaling
- **Range-Based Strategy**: Sequential/time-series data optimization

**Metrics Measured:**
- Operations per second
- Distribution balance score
- Numeric key performance
- String key performance
- Variance across shards

## Benchmark Results

### Sample Output (10,000 operations, 5 shards)

```
Testing Modulo Strategy...
  ⏱️  Numeric Keys: 45.32ms
  ⏱️  String Keys:  52.18ms
  🚀 Operations/sec: 204,918
  ⚖️  Balance Score: 100%

Testing Consistent Hashing Strategy...
  ⏱️  Numeric Keys: 67.45ms
  ⏱️  String Keys:  71.23ms
  🚀 Operations/sec: 144,230
  ⚖️  Balance Score: 96.5%

Testing Range-Based Strategy...
  ⏱️  Numeric Keys: 48.67ms
  ⏱️  String Keys:  55.91ms
  🚀 Operations/sec: 191,204
  ⚖️  Balance Score: 85.2%
```

## Interpretation

- **Modulo** is fastest for simple numeric keys with perfect distribution
- **Consistent Hashing** provides best resilience for dynamic scaling
- **Range-Based** works best for sequential or time-based data

Choose your strategy based on your specific use case and scaling requirements.
