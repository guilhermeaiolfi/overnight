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
| `ConsoleExtension` | CLI commands | |
| `CMSExtension` | Built-in CMS | |
| `MaintenanceExtension` | Maintenance mode | [maintenance.md](extensions/maintenance.md) |
| `ClockworkExtension` | Debug profiling | |
| `FileRoutingExtension` | File-based routing | |
| `GraphQLExtension` | GraphQL API support | [graphql.md](extensions/graphql.md) |
| `AutoWiringExtension` | Automatic application extension discovery | [auto-wiring.md](extensions/auto-wiring.md) |

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
use ON\Config\Init\ConfigInitEvents;
use ON\Config\Init\Event\ConfigConfigureEvent;

class MyExtension extends AbstractExtension
{
    public static function install(Application $app, ?array $options): mixed
    {
        // Called once when extension is installed
        return true;
    }

    public function setup(): void
    {
        // Called during setup phase.
        // Register event listeners here.
        $this->app->init()->on(ConfigInitEvents::CONFIGURE, function(ConfigConfigureEvent $event) {
            $config = $event->getConfig();
            
            // Register services in the container
            $config->set('container.my_service', function($container) {
                return new MyService();
            });
        });
    }

    public function boot(): void
    {
        // Called during boot phase
        // Final configuration or late-stage initialization
    }

    public function requires(): array
    {
        // Return required extensions
        return [RouterExtension::class];
    }

    public function getNamespace(): string
    {
        // Return namespace for discovery
        return 'App\\';
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
install()    → Called once when $app->install() is called
    ↓
setup()      → Called during app setup phase
    ↓
CONFIGURE    → (ConfigInitEvents) Register services and configuration
    ↓
boot()       → Called during app boot phase
    ↓
READY        → (ConfigInitEvents) App is ready to run
```

### Lifecycle Callbacks

```php
$extension->when('setup', function() {
    // Called when transitioning to setup state
});

$extension->when('boot', function() {
    // Called when transitioning to boot state
});

$extension->when('ready', function() {
    // Called when app is ready
});
```

## Deferred Execution

Register callbacks to run later:

```php
public function setup(): void
{
    // Run after setup phase
    $this->nextTick(function() {
        $this->registerRoutes();
    });
}
```

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

Declare dependencies:

```php
class MyExtension extends AbstractExtension
{
    public function requires(): array
    {
        return [
            RouterExtension::class,
            ContainerExtension::class,
        ];
    }
}
```

The framework will ensure dependencies are installed first.

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
2. **Declare dependencies** - Use `requires()` to specify what you need
3. **Use lifecycle phases** - Use `setup()` for event registration, and `ConfigInitEvents::CONFIGURE` for service registration.
4. **Prefer Config Registration** - Registering services via `ConfigInitEvents::CONFIGURE` ensures they are cached and can be overridden by user configuration files.
5. **Provide sensible defaults** - Accept options array for configuration
6. **Test independently** - Extensions should be testable in isolation
