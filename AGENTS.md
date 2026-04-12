# Agent Guidance for Overnight Framework

## Quick Commands

```bash
# Run all tests
php vendor/bin/phpunit

# Run specific test
php vendor/bin/phpunit tests/Router/ActionMiddlewareDecoratorTest.php

# Run linter (PSR12 + custom rules)
vendor/bin/php-cs-fixer fix

# Run with coverage
php vendor/bin/phpunit --coverage-text
```

## Architecture

- **Namespace**: `ON\` for source code (`src/`), `Tests\ON\` for tests (`tests/`)
- **Framework type**: PHP library with PSR-7/PSR-15 compliance
- **Main directories**: `src/Application.php`, `src/Container/`, `src/Router/`, `src/ORM/`

## Testing Controllers with Route Injection

When testing pages/controllers that receive route parameters, use this pattern from `docs/testing.md`:

```php
$parameterResolver = new ResolverChain([
    new TypeHintResolver(),
    new NumericArrayResolver(),
    new AssociativeArrayResolver(),
    new DefaultValueResolver(),
    new TypeHintContainerResolver($this->container),
]);
$executor = new Executor($parameterResolver, $this->container);
```

## Known Issues

- Some ORM tests (e.g., `SelectTest`) require full ORM setup with constructor arguments - may need mocking or placeholder tests
- Dependencies may need `composer install` after any lockfile changes

## References

- Full testing guide: `docs/testing.md`
- Framework docs: `docs/README.md`
- php-cs-fixer config: `.php-cs-fixer.php`