# Contributing to Laravel Redis Sharding

Thank you for considering contributing to Laravel Redis Sharding! We welcome contributions from the community.

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code. Please be respectful and constructive in all interactions.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When creating a bug report, include:

- A clear and descriptive title
- Steps to reproduce the behavior
- Expected behavior vs actual behavior
- Your environment (PHP version, Laravel version, Redis version)
- Any relevant code samples or error messages

### Suggesting Enhancements

Enhancement suggestions are welcome! Please provide:

- A clear and descriptive title
- Detailed description of the proposed functionality
- Explain why this enhancement would be useful
- Examples of how it would be used

### Pull Requests

1. **Fork the repository** and create your branch from `main`
2. **Write tests** for your changes
3. **Ensure tests pass**: Run `vendor/bin/phpunit`
4. **Follow code style**: Run `vendor/bin/php-cs-fixer fix`
5. **Update documentation** if needed
6. **Write a clear commit message**

#### Development Setup

```bash
# Clone your fork
Git clone https://github.com/your-username/laravel-shard.git
cd laravel-shard

# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Run code style checks
vendor/bin/php-cs-fixer fix --dry-run

# Run static analysis
vendor/bin/phpstan analyse
```

#### Code Style

- Follow PSR-12 coding standards
- Use type hints wherever possible
- Add PHPDoc blocks for public methods
- Keep methods focused and concise

#### Testing

- Write tests for new features
- Ensure all tests pass before submitting PR
- Aim for high code coverage
- Test edge cases

### Test Requirements

Tests require:
- **Redis server** running on localhost:6379
- **SQLite** support
- **PHP 8.2+**

To run tests:
```bash
# All tests
vendor/bin/phpunit

# Specific test suite
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Integration
vendor/bin/phpunit --testsuite=Feature
```

## Development Process

1. **Create an issue** for major changes before starting work
2. **Work on a feature branch** named descriptively (e.g., `feature/cross-shard-joins`)
3. **Keep commits atomic** and well-described
4. **Rebase on main** before submitting PR
5. **Reference issues** in commit messages and PRs

## Questions?

Feel free to open an issue for questions or join discussions.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
