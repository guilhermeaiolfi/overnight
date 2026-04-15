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
- **PHP version**: 8.1+ (running 8.3)
- **Framework type**: PSR-7/PSR-15 compliant PHP library with extension-based architecture
- **Entry point**: `src/Application.php` — loads extensions, manages lifecycle
- **Shell**: bash on Windows (Laragon)

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
│   ├── CachedGraphQLRegistryGenerator.php
│   ├── DataLoader/GenericDataLoader.php
│   ├── Error/GraphQLUserError.php
│   ├── Event/BeforeMutation.php, AfterMutation.php
│   ├── GraphQLExtension.php
│   ├── GraphQLRegistryGenerator.php
│   ├── Middleware/GraphQLMiddleware.php
│   ├── Resolver/GraphQLResolverInterface.php
│   ├── Resolver/SqlResolver.php
│   ├── Resolver/CycleResolver.php
│   └── Type/UploadType.php
├── Middleware/                   # PSR-15 pipeline
├── ORM/                         # Cycle ORM wrapper with custom definition system
│   ├── Definition/              # Registry, Collection, Field, Relation
│   ├── Compiler/                # Converts ON definitions to Cycle schema
│   └── Select/                  # Query builder, loaders, repository
├── Router/                      # FastRoute integration
├── View/                        # Template engines (Plates built-in, Latte extension)
└── ...
```

## Extension System

Extensions follow a lifecycle: `install()` → `boot()` → `setup()` → `ready`.

- Extensions declare dependencies via `requires()` returning extension names
- Cross-extension communication uses `$this->app->ext('name')->when('state', $callback)`
- Access extensions via `$this->app->ext('name')` or shortcuts like `$this->app->events`, `$this->app->container`
- Events extension: `$this->app->events->dispatch($event)` or `$this->app->events->eventDispatcher`

## ORM Definition Conventions

Fluent builder pattern with `->end()` to navigate back up:

```php
$registry->collection('user')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('name', 'string')->validation('required|max:255')->end()
    ->field('email', 'string')->validation('required|email')->end()
    ->field('password', 'string')->hidden(true)->end()
    ->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
    ->belongsTo('role', 'role')->innerKey('role_id')->outerKey('id')->end()
    ->end();
```

Key rules:
- `field('name', 'type')` — second arg is optional shorthand for `->type('type')`
- `hasMany()`, `hasOne()`, `belongsTo()` — convenience methods, no need to import relation classes
- `innerKey` = key on the **source** entity (the one defining the relation); `outerKey` = key on the **target** entity (Cycle ORM convention)
- For hasMany/hasOne: `innerKey` is typically the PK (`id`), `outerKey` is the FK on the target (`user_id`)
- For belongsTo: `innerKey` is the FK on the source (`user_id`), `outerKey` is the PK on the target (`id`)
- `getCardinality()` returns `'single'` or `'many'`; `isJunction()` for M2M
- `validation('rules')` — pipe-delimited rules using `somnambulist/validation`
- `hidden(true)` — excludes from GraphQL schema
- `metadata('key', value)` — both getter (1 arg) and setter (2 args)
- Primitive properties are protected with getters/setters
- Object properties (`fields`, `relations`, `through`) are public: `$collection->fields->get('name')`
- `Registry::getCollection()` returns `?CollectionInterface` (nullable)

## GraphQL Extension

The generator takes `Registry` + optional `Resolver` + optional `EventDispatcher`:

```php
$generator = new GraphQLRegistryGenerator($registry, $resolver, $eventDispatcher);
$schema = $generator->generate();
```

- **No container in constructor** — that's an antipattern. Resolvers and dispatchers are injected directly.
- **Resolvers**: `SqlResolver` (raw PDO), `CycleResolver` (Cycle ORM), or custom `GraphQLResolverInterface`
- **Extension config**: `'resolver' => 'auto'|'sql'|'cycle'|MyResolver::class`
- Auto-detection: `CycleDatabase` → `CycleResolver`, otherwise `SqlResolver`
- List queries return connection types: `{ items: [...], totalCount: N }`
- Delete mutations return the deleted object (not boolean)
- Mutations dispatch `BeforeMutation`/`AfterMutation` events
- File uploads via multipart request spec, `Upload` scalar for `file`/`image`/`upload` field types
- Schema cached in production via `CachedGraphQLRegistryGenerator`

## Testing Conventions

- Test namespace: `Tests\ON\` mapped to `tests/`
- GraphQL SQL tests require `pdo_sqlite` — use `#[RequiresPhpExtension('pdo_sqlite')]`
- Test database: `SqliteTestDatabase` (real SQLite in-memory) in `tests/GraphQL/Support/`
- Shared fixtures: `GraphQLTestFixtures` trait with `createUserCollection()`, `createTestDatabase()`, etc.
- Tests that need a database: `new GraphQLRegistryGenerator($registry, new SqlResolver($registry, $database))`

## Code Style & Patterns

- **Property visibility**: primitive values → protected with getters/setters; object instances → public
- **No `use function` imports** — `implode`, `sprintf`, etc. are global and don't need explicit imports
- **Resolver pattern**: interface + implementations (SqlResolver, CycleResolver), not factory methods on the generator
- **Events over callbacks**: use `BeforeMutation`/`AfterMutation` events for mutation lifecycle hooks
- **Validation**: `somnambulist/validation` with pipe syntax on field definitions, not in resolvers
- **Error handling**: `GraphQLUserError` implements `ClientAware` + `ProvidesExtensions` for structured GraphQL errors
- **SQL safety**: `quoteIdentifier()` strips non-alphanumeric chars and wraps in backticks; values always parameterized
- **LIKE filtering**: string values containing `%` automatically use `LIKE` instead of `=`

## Database Layer

- `DatabaseManager` (was `Manager`) — manages named database connections
- `DatabaseInterface` — contract for database implementations
- `CycleDatabase` — Cycle ORM wrapper (has ORM + EntityManager)
- `PdoDatabase` — raw PDO wrapper
- Access: `$this->app->container->get(DatabaseManager::class)->getDatabase()`

## View System

- `ViewExtension` — core view with Plates built-in
- `LatteExtension` (`ON\View\Latte\LatteExtension`) — separate extension for Latte
- Template engines are extensions, not drivers — each registers its renderer in the container
- `RendererInterface` — common contract for all template engines

## Known Issues & Gotchas

- `pdo_sqlite` must be enabled in php.ini for SQL resolver tests (line 961 in php.ini)
- `AuthorizationService` throws `NotImplementedException` — not yet implemented
- Some ORM tests require full Cycle ORM setup with constructor arguments
- `composer install` needed after lockfile changes
- The `Extension/` subfolder under `GraphQL/` was removed — extension is at `ON\GraphQL\GraphQLExtension`
- `GraphQLSchemaFactory` was deleted — schema creation is inline in the extension

## References

- Framework docs: `docs/README.md`
- GraphQL docs: `docs/extensions/graphql.md`
- DataLoader docs: `docs/extensions/graphql-dataloader.md`
- ORM entity docs: `docs/orm-entity-definition.md`
- View docs: `docs/extensions/views.md`
- Testing guide: `docs/testing.md`
- php-cs-fixer config: `.php-cs-fixer.php`
- PHPUnit config: `phpunit.xml`
