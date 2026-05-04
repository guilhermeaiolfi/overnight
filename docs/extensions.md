# Extensions

Extensions are modular components that extend the framework's functionality.

## Built-in Extensions

| Extension | Purpose | Documentation |
|-----------|---------|---------------|
| `ConfigExtension` | Configuration loading & caching | |
| `ContainerExtension` | PHP-DI container | [di-container.md](extensions/di-container.md) |
| `RouterExtension` | Route registration helpers | [routing.md](extensions/routing.md) |
| `ViewExtension` | Template rendering | [views.md](extensions/views.md) |
| `AuthExtension` | Authentication/authorization | [auth.md](extensions/auth.md) |
| `SessionExtension` | Session management | [sessions.md](extensions/sessions.md) |
| `TranslationExtension` | Internationalization | [translation.md](extensions/translation.md) |
| `LoggingExtension` | Monolog logging | |
| `CacheExtension` | Symfony Cache integration | |
| `DatabaseExtension` | ORM/database | [database.md](extensions/database.md) |
| `ORMExtension` | Cycle ORM wrapper | [orm-entity-definition.md](orm-entity-definition.md) |
| `EventsExtension` | Event dispatcher | [events.md](extensions/events.md) |
| `DiscoveryExtension` | Class discovery | [discovery.md](extensions/discovery.md) |
| `ConsoleExtension` | CLI commands | [cli.md](cli.md) |
| `CMSExtension` | Built-in CMS | |
| `MaintenanceExtension` | Maintenance mode | [maintenance.md](extensions/maintenance.md) |
| `ClockworkExtension` | Debug profiling | |
| `FileRoutingExtension` | File-based routing | [file-routing.md](extensions/file-routing.md) |
| `GraphQLExtension` | GraphQL API support | [graphql.md](extensions/graphql.md) |
| `AutoWiringExtension` | Automatic application extension discovery | [auto-wiring.md](extensions/auto-wiring.md) |
| `RestApiExtension` | REST API endpoints from entity definitions | [rest-api.md](extensions/rest-api.md) |
| `ImageExtension` | Image processing | [image.md](extensions/image.md) |
| `RateLimitExtension` | Rate limiting | |
| `PipelineExtension` | PSR-15 middleware pipeline | |

## Extension Documentation

Detailed documentation for each extension is available in the `docs/extensions/` folder:

- [Routing](extensions/routing.md) - Route registration and configuration
- [DI Container](extensions/di-container.md) - PHP-DI container setup
- [Views](extensions/views.md) - Template rendering with Plates
- [Authentication](extensions/auth.md) - User authentication and authorization
- [Sessions](extensions/sessions.md) - Session management
- [Translation](extensions/translation.md) - Internationalization (i18n)
- [Database](extensions/database.md) - ORM and database configuration
- [Events](extensions/events.md) - Event dispatcher
- [File Routing](extensions/file-routing.md) - File-based directory routing
- [Discovery](extensions/discovery.md) - Attribute-based class discovery
- [Maintenance](extensions/maintenance.md) - Maintenance mode
- [Image](extensions/image.md) - Image processing
- [GraphQL](extensions/graphql.md) - GraphQL API support
- [GraphQL DataLoader](extensions/graphql-dataloader.md) - N+1 problem solutions
- [Auto-Wiring](extensions/auto-wiring.md) - Automatic application extension discovery
- [REST API](extensions/rest-api.md) - Directus-style REST API from entity definitions

## Installing Extensions

```php
$app = new Application();

// By class name
$app->install(RouterExtension::class);

// With options
$app->install(ViewExtension::class, [
    'engine' => 'plates',
    'path' => 'templates',
]);
```

## Creating an Extension

```php
<?php

namespace App\Extensions;

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Init\Init;
use ON\Init\InitContext;

class MyExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        // Register event listeners here.
        // Events are auto-typed — pass an event class instance or class-string.
        $init->on(ConfigConfigureEvent::class, function(ConfigConfigureEvent $event) {
            $config = $event->config;
            
            // Register services in the container
            $config->set('container.my_service', function($container) {
                return new MyService();
            });
        });
    }

    public function start(InitContext $ctx): void
    {
        // Start phase — called in resolved dependency order
    }

    public function getType(): int
    {
        return self::TYPE_EXTENSION;
    }
}
```

## Extension Types

```php
class MyExtension extends AbstractExtension
{
    // Module - Provides a feature
    const TYPE_MODULE = 1;

    // Extension - Extends existing features
    const TYPE_EXTENSION = 2;

    // Aggregation - Aggregates multiple extensions
    const TYPE_AGGREGATION = 3;
}
```

## Lifecycle

```
Constructor     → Called when extension is instantiated via new $class($app, $options)
    ↓
register()      → Called for ALL extensions (collects event subscriptions)
    ↓
resolveOrder()  → Lifecycle order inferred from event subscriptions (cached)
    ↓
sortListeners() → Listeners sorted by extension order
    ↓
start()         → Called in resolved order via InitContext
```

### Listener Ordering

Extension startup order is **automatically inferred** from event subscriptions. If extension A listens to an event emitted by extension B, A will start after B. This replaces the old `requires()` method. The ordering is:

1. All extensions call `register()` to subscribe to events
2. A dependency graph is built from subscription patterns (who listens to whose events)
3. Topological sort determines execution order
4. The result is cached to `var/cache/app_lifecycle.php` in non-debug mode

## Extension Configuration

Config classes (like `RouterConfig`, `ContainerConfig`, `ViewConfig`, `DatabaseConfig`) are auto-registered by their respective extensions and obtained from the container. You do not need to pass them to `$app->install()` — they are picked up from config files or registered during extension startup.

### RouterConfig

```php
use ON\Router\RouterConfig;

// RouterConfig is registered by RouterExtension and available from the container
$routerCfg = $app->config->get(RouterConfig::class);

// Add routes via the router extension
$router = $app->ext('router');
$router->get('/users', 'UserPage::index');
$router->get('/users/{id}', 'UserPage::show');

// Route groups
$router->group('/api', function($r) {
    $r->get('/users', 'ApiUserPage::index');
});
```

### ContainerConfig

```php
use ON\Container\ContainerConfig;

// ContainerConfig is registered by ContainerExtension
$containerCfg = $app->config->get(ContainerConfig::class);

// Register services (usually done in config files or during lifecycle events)
$containerCfg->addService(MyService::class, function($c) {
    return new MyService($c->get(Dependency::class));
});
```

### ViewConfig

```php
use ON\View\ViewConfig;

// ViewConfig is registered by ViewExtension
$viewCfg = $app->config->get(ViewConfig::class);
```

### DatabaseConfig

```php
use ON\Db\DatabaseConfig;

// DatabaseConfig is registered by DatabaseExtension
$dbCfg = $app->config->get(DatabaseConfig::class);
```

### LoggingConfig

```php
use ON\Logging\LoggingConfig;

// LoggingConfig is registered by LoggingExtension
$logCfg = $app->config->get(LoggingConfig::class);
```

## Accessing Extensions

```php
// Get by registered ID (defined in extension's ID constant)
$router = $app->ext('router');
$container = $app->ext('container');
$config = $app->ext('config');

// Get by class name
$router = $app->ext(RouterExtension::class);

// Extensions are also accessible as dynamic properties
$router = $app->router;
$container = $app->container;

// Check if installed
if ($app->hasExtension(MyExtension::class)) {
    // ...
}
```

## Extension Dependencies

Dependencies are **automatically inferred** from event subscriptions. If extension A listens to an event whose class belongs to extension B's namespace, A will be ordered after B. No manual `requires()` declaration needed.

The dependency graph is built during `rebuildLifecycleOrder()` and cached to `var/cache/app_lifecycle.php` in production mode. In debug mode the order is recalculated on every request.

### Method Registration Conflicts

Only one extension may register a given `run` method (or any magic `__call` method). If multiple extensions attempt to register the same method name, an exception is thrown at startup:

```php
$app->registerMethod('run', $someCallable);
```

## Auto-Discovery

Extensions can auto-discover classes with attributes:

```php
class DiscoveryExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        $discovery = $this->app->ext('discovery');
        
        $discovery->addProcessor(RouteAttributeProcessor::class, [
            'location' => 'App\\Routes',
        ]);
    }
}
```

## Best Practices

1. **Keep extensions focused** - One extension, one responsibility
2. **Use event objects** - Use typed event classes (e.g. `ConfigConfigureEvent::class`) instead of enum-based event names for listener registration.
3. **Auto-ordered lifecycle** - No need to declare `requires()`; dependency order is inferred from event subscription patterns.
4. **Pass via Context** - Use `$ctx->emit(new EventClass($this))` from within `start()` to dispatch events in the resolved order.
5. **Provide sensible defaults** - Accept options array for configuration
6. **Test independently** - Extensions should be testable in isolation
