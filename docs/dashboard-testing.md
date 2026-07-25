# Dashboard Testing Guide

## Running Tests

### PHP Tests
```bash
# All tests
composer test

# With coverage
composer test:coverage

# Specific test
vendor/bin/phpunit tests/php/Unit/HelpersTest.php
vendor/bin/phpunit tests/php/Integration/ApiEndpointsTest.php
```

### JavaScript Tests
```bash
# All tests
npm test

# Watch mode
npm run test:watch

# With coverage
npm run test:coverage
```

### Static Analysis
```bash
# PHPStan
composer stan

# CodeSniffer
composer cs

# Auto-fix
composer cs:fix

# ESLint
npm run lint
npm run lint:fix
```

## CI Pipeline
Runs on every push/PR to main/develop:
1. PHP Unit + Integration tests
2. PHPStan level 5
3. Vitest JS tests
4. ESLint

## Adding New Tests
- PHP unit: `tests/php/Unit/`
- PHP integration: `tests/php/Integration/`
- JS unit: `tests/js/`

## Test Structure
- **Unit tests** test isolated functions without external dependencies
- **Integration tests** test API endpoints by spawning PHP subprocesses
- **JS tests** use Vitest with jsdom environment for DOM testing
