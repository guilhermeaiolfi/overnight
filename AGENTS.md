# Agent Guidance for Overnight Framework

## Quick Commands

```bash
# Run all tests
php vendor/bin/phpunit

# Run specific test file
php vendor/bin/phpunit tests/GraphQL/GraphQLSQLResolverTest.php

# Run specific test method
php vendor/bin/phpunit --filter=testExecuteGraphQLQueryWithRelations

# Run tests for a module
php vendor/bin/phpunit tests/GraphQL/

# Run linter (PSR12 + custom rules)
vendor/bin/php-cs-fixer fix

# Run with coverage
php vendor/bin/phpunit --coverage-text
```

## Architecture

- **Namespace**: `ON\` for source (`src/`), `Tests\ON\` for tests (`tests/`)
- **PHP version**: 8.1+
- **Framework type**: PSR-7/PSR-15 compliant PHP library with extension-based architecture
- **Entry point**: `src/Application.php` — loads extensions, manages lifecycle

## Project Structure

```
src/
├── Application.php              # Core bootstrap, extension lifecycle
├── Auth/                        # Authentication & authorization
├── Cache/                       # Symfony Cache wrapper
├── Clockwork/                   # Debug profiler integration
├── CMS/                         # Built-in CMS (DataHandler, QueryParser)
├── Config/                      # Configuration system with Scanner
├── Console/                     # Symfony Console integration
├── Container/                   # PHP-DI based DI container
├── DB/                          # Database abstraction (DatabaseManager, CycleDatabase, PdoDatabase)
├── Discovery/                   # Attribute-based class discovery
├── Event/                       # League Event integration
├── Extension/                   # AbstractExtension base class
├── GraphQL/                     # GraphQL schema generation & CRUD
├── Middleware/                   # PSR-15 pipeline
├── ORM/                         # Cycle ORM wrapper with custom definition system
├── Router/                      # FastRoute integration
├── View/                        # Template engines (Plates built-in, Latte extension)
└── ...
```

## Extension System

- Access extensions via `$this->app->ext('name')` or shortcuts like `$this->app->events`, `$this->app->container`
- Events extension access: `$this->app->events->dispatch($event)`

## ORM Definition Conventions

Fluent builder pattern with `->end()` to navigate back up:

```php
$registry->collection('user')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('name', 'string')->validation('required|max:255')->end()
    ->field('password', 'string')->hidden(true)->end()
    ->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
    ->belongsTo('role', 'role')->innerKey('role_id')->outerKey('id')->end()
    ->end();
```

Key rules:
- `innerKey` = key on the **source** entity (the one defining the relation); `outerKey` = key on the **target** entity (Cycle ORM convention)
- For hasMany/hasOne: `innerKey` is typically the PK (`id`), `outerKey` is the FK on the target (`user_id`)
- For belongsTo: `innerKey` is the FK on the source (`user_id`), `outerKey` is the PK on the target (`id`)
- `getCardinality()` returns `'single'` or `'many'`; `isJunction()` for M2M
- `validation('rules')` — pipe-delimited rules using `somnambulist/validation`
- Object properties (`fields`, `relations`, `through`) are public: `$collection->fields->get('name')`

## Testing Conventions

- Test namespace: `Tests\ON\` mapped to `tests/`

## Code Style & Patterns

- **Validation**: `somnambulist/validation` with pipe syntax on field definitions, not in resolvers

## Database Layer

- `CycleDatabase` — Cycle ORM wrapper (has ORM + EntityManager)
- `PdoDatabase` — raw PDO wrapper
- Access: `$this->app->container->get(DatabaseManager::class)->getDatabase()`