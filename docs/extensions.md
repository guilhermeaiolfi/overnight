# Extensions

Extensions are modular components that extend the framework's functionality.

## Built-in Extensions

| Extension | Purpose |
|-----------|---------|
| `RouterExtension` | Route registration helpers |
| `ContainerExtension` | PHP-DI container |
| `ViewExtension` | Template rendering |
| `AuthExtension` | Authentication/authorization |
| `SessionExtension` | Session management |
| `TranslationExtension` | Internationalization |
| `LoggingExtension` | Monolog logging |
| `DatabaseExtension` | ORM/database |
| `EventsExtension` | Event dispatcher |
| `DiscoveryExtension` | Class discovery |
| `ConsoleExtension` | CLI commands |
| `CMSExtension` | Built-in CMS |
| `MaintenanceExtension` | Maintenance mode |
| `ClockworkExtension` | Debug profiling |
| `FileRoutingExtension` | File-based routing |

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

class MyExtension extends AbstractExtension
{
    public static function install(Application $app, ?array $options): mixed
    {
        // Called once when extension is installed
        return true;
    }

    public function setup(): void
    {
        // Called during setup phase
        // Register services, middleware, routes
    }

    public function boot(): void
    {
        // Called during boot phase
        // Final configuration, event listeners
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
boot()       → Called during app boot phase
    ↓
READY        → App is ready to run
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
3. **Use lifecycle phases** - Setup for registration, boot for final config
4. **Provide sensible defaults** - Accept options array for configuration
5. **Test independently** - Extensions should be testable in isolation
