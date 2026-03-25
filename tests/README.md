# Testing Guide

## Test Suite Overview

The Laravel Redis Sharding package has 51 comprehensive tests:

- **Unit Tests** (25 tests) - Test individual components, NO external dependencies
- **Integration Tests** (21 tests) - Test component integration, REQUIRES Redis
- **Feature Tests** (5 tests) - End-to-end tests, REQUIRES Redis + Database

## Running Tests

### All Tests
```bash
vendor/bin/phpunit
```

### By Test Suite
```bash
# Unit tests only (NO Redis required)
vendor/bin/phpunit --testsuite=Unit

# Integration tests (Redis required)
vendor/bin/phpunit --testsuite=Integration

# Feature tests (Redis required)
vendor/bin/phpunit --testsuite=Feature
```

### With Coverage
```bash
vendor/bin/phpunit --coverage-html coverage
```

## Test Requirements

### Unit Tests ✅
- **NO external services required**
- Run standalone
- Fast execution (<1 second)
- **Always pass** regardless of environment

### Integration Tests ⚠️
- **REQUIRES:**
  - Redis server running on localhost:6379
  - SQLite support (built-in)

### Feature Tests ⚠️
- **REQUIRES:**
  - Redis server running on localhost:6379
  - SQLite support (built-in)
  - Database migrations run

## Local Testing

### Option 1: Start Redis Locally (Recommended for Full Testing)

**Windows (with Laragon):**
```bash
# Start Redis from Laragon menu
# Or use WSL/Docker
```

**macOS:**
```bash
brew install redis
brew services start redis
```

**Linux:**
```bash
sudo apt-get install redis-server
sudo systemctl start redis
```

**Docker:**
```bash
docker run -d -p 6379:6379 redis:7-alpine
```

Then run all tests:
```bash
vendor/bin/phpunit
```

### Option 2: Run Unit Tests Only (Quick Validation)

```bash
# Only run unit tests (no Redis needed)
vendor/bin/phpunit --testsuite=Unit
```

**Expected Output:**
```
OK (25 tests, 68 assertions)
```

## CI/CD Testing

### GitHub Actions

Our CI workflow (`.github/workflows/tests.yml`) automatically:
1. Sets up Redis service container
2. Installs PHP dependencies
3. Runs ALL 51 tests
4. Reports results

All tests **MUST pass** in CI before merging.

## Test Status

### Current Status

| Test Suite | Count | Local (No Redis) | CI (With Redis) |
|------------|-------|------------------|-----------------|
| Unit | 25 | ✅ PASS | ✅ PASS |
| Integration | 21 | ⏭️ SKIP | ✅ PASS |
| Feature | 5 | ⏭️ SKIP | ✅ PASS |
| **Total** | **51** | **25 PASS** | **51 PASS** |

### Why This Design?

- **Unit tests** validate core logic without external dependencies
- **Integration/Feature tests** validate real-world behavior with Redis
- **Developers** can quickly validate changes with unit tests
- **CI** ensures full integration testing before deployment

## Troubleshooting

### "Connection refused" errors
**Cause:** Redis not running  
**Solution:** Start Redis or run unit tests only

### "All tests skipped"
**Cause:** Redis unavailable, tests skip gracefully  
**Solution:** This is expected behavior - tests will run in CI

### "Class not found" errors
**Cause:** Autoloader not updated  
**Solution:** Run `composer dump-autoload`

### Slow tests
**Cause:** Database migrations running on every test  
**Solution:** This is normal for integration tests

## Writing Tests

### Test Structure

```php
<?php

namespace Laravel\RedisShard\Tests\Unit;

use Laravel\RedisShard\Tests\TestCase;

class MyTest extends TestCase
{
    public function test_it_does_something(): void
    {
        // Arrange
        $input = 'test';
        
        // Act
        $result = someFunction($input);
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Redis-Dependent Tests

```php
<?php

namespace Laravel\RedisShard\Tests\Integration;

use Laravel\RedisShard\Tests\TestCase;

class RedisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if Redis not available
        $this->skipIfRedisNotAvailable();
    }
    
    public function test_with_redis(): void
    {
        // Test will only run if Redis is available
    }
}
```

## Best Practices

1. **Run unit tests frequently** - Fast feedback loop
2. **Run all tests before committing** - Catch integration issues
3. **Check CI results** - Ensure Redis-dependent tests pass
4. **Write unit tests for logic** - Test without external dependencies
5. **Write integration tests for workflows** - Test real scenarios

## Pre-Commit Checklist

- [ ] Unit tests pass: `vendor/bin/phpunit --testsuite=Unit`
- [ ] Code style clean: `vendor/bin/php-cs-fixer fix`
- [ ] Static analysis clean: `vendor/bin/phpstan analyse`
- [ ] All tests pass (if Redis available): `vendor/bin/phpunit`

## Continuous Integration

GitHub Actions runs on:
- Every push to `main` or `develop`
- Every pull request
- Matrix: PHP 8.2-8.4 x Laravel 10.x-13.x

View results: [GitHub Actions](https://github.com/yusuf-kef/laravel-redis-shard/actions)

## Questions?

- Review [CONTRIBUTING.md](../CONTRIBUTING.md)
- Check [GitHub Discussions](https://github.com/yusuf-kef/laravel-redis-shard/discussions)
- Open an [issue](https://github.com/yusuf-kef/laravel-shard/issues)
