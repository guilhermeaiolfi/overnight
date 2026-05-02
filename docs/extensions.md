# Extensions

Extensions are modular components that extend the framework's functionality.

## Built-in Extensions

| Extension | Purpose | Documentation |
|-----------|---------|---------------|
| `RouterExtension` | Route registration helpers | [routing.md](extensions/routing.md) |
| `ContainerExtension` | PHP-DI container | [di-container.md](extensions/di-container.md) |
| `ViewExtension` | Template rendering | [views.md](extensions/views.md) |
| `AuthExtension` | Authentication/authorization | [auth.md](extensions/auth.md) |
| `SessionExtension` | Session management | [sessions.md](extensions/sessions.md) |
| `TranslationExtension` | Internationalization | [translation.md](extensions/translation.md) |
| `LoggingExtension` | Monolog logging | |
| `DatabaseExtension` | ORM/database | [database.md](extensions/database.md) |
| `EventsExtension` | Event dispatcher | [events.md](extensions/events.md) |
| `DiscoveryExtension` | Class discovery | |
| `ConsoleExtension` | CLI commands | [cli.md](cli.md) |
| `CMSExtension` | Built-in CMS | |
| `MaintenanceExtension` | Maintenance mode | [maintenance.md](extensions/maintenance.md) |
| `ClockworkExtension` | Debug profiling | |
| `FileRoutingExtension` | File-based routing | |
| `GraphQLExtension` | GraphQL API support | [graphql.md](extensions/graphql.md) |
| `AutoWiringExtension` | Automatic application extension discovery | [auto-wiring.md](extensions/auto-wiring.md) |
| `RestApiExtension` | REST API endpoints from entity definitions | [rest-api.md](extensions/rest-api.md) |
| `ImageExtension` | Image processing | |
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
- [Maintenance](extensions/maintenance.md) - Maintenance mode
- [GraphQL](extensions/graphql.md) - GraphQL API support
- [Auto-Wiring](extensions/auto-wiring.md) - Automatic application extension discovery

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

class MyExtension extends AbstractExtension
{
    public static function install(Application $app, ?array $options): mixed
    {
        // Called once when extension is installed
        return true;
    }

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
install()       → Called once when $app->install() is called
    ↓
register()      → Called for ALL extensions (collects event subscriptions)
    ↓
resolveOrder()  → Lifecycle order inferred from event subscriptions (cached)
    ↓
start()         → Called in resolved order via $init->context()->emit()
```

### Listener Ordering

Extension startup order is **automatically inferred** from event subscriptions. If extension A listens to an event emitted by extension B, A will start after B. This replaces the old `requires()` method. The ordering is:

1. All extensions call `register()` to subscribe to events
2. A dependency graph is built from subscription patterns (who listens to whose events)
3. Topological sort determines execution order
4. The result is cached to `var/cache/app_lifecycle.php` in non-debug mode

## Extension Configuration

### RouterConfig

```php
use ON\Router\RouterConfig;

$app->install(new RouterConfig());

$router = $app->ext('router');

// Add routes
$router->addRoute('/users', 'UserPage::index', ['GET']);
$router->addRoute('/users/{id}', 'UserPage::show', ['GET']);

// Route groups
$router->addGroup('/api', function($r) {
    $r->addRoute('/users', 'ApiUserPage::index');
});
```

### ContainerConfig

```php
use ON\Container\ContainerConfig;

$app->install(new ContainerConfig());

$container = $app->ext('container');

// Register services
$container->define(MyService::class, function($c) {
    return new MyService($c->get(Dependency::class));
});
```

### ViewConfig

```php
use ON\View\ViewConfig;

$config = new ViewConfig([
    'engine' => 'plates',
    'path' => 'templates',
]);

$app->install($config);
```

### DatabaseConfig

```php
use ON\Db\DatabaseConfig;

$config = new DatabaseConfig();
$config->addDatabase('default', 'mysql:host=localhost;dbname=app', 'root', 'password');

$app->install($config);
```

### LoggingConfig

```php
use ON\Logging\LoggingConfig;

$config = new LoggingConfig([
    'default' => [
        'type' => 'rotating_file',
        'path' => 'logs/app.log',
    ],
]);

$app->install($config);
```

## Accessing Extensions

```php
// Get by class name
$router = $app->ext(RouterConfig::class);

// Get by registered name
$router = $app->ext('router');

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
    public function setup(): void
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
4. **Pass via Context** - Use `$this->app->init()->context()->emit(EventClass::class)` from within `start()` to dispatch events in the resolved order.
5. **Provide sensible defaults** - Accept options array for configuration
6. **Test independently** - Extensions should be testable in isolation
