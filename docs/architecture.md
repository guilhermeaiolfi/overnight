# Architecture

## Overview

Overnight is a modular PHP framework built around PSR-7/PSR-15 standards. The architecture follows an extension-based pattern where features are registered as installable components.

## Request Lifecycle

```
Request → Middleware Pipeline → Router → ActionMiddlewareDecorator → Page → Response
                ↓
        [Middleware processing in priority order]
```

### Step-by-Step Flow

1. **HTTP Request** - PSR-7 ServerRequest is created
2. **Middleware Pipeline** - Processes request through priority-ordered middleware
3. **Routing** - FastRoute matches URL to route pattern
4. **Route Result** - Stores matched route and parameters
5. **Action Decorator** - Resolves dependencies and executes page method
6. **Page Method** - Your controller code runs with auto-injected parameters
7. **View Building** - Response is built/rendered
8. **HTTP Response** - PSR-7 Response returned

## Core Components

### Application

The `Application` class is the main entry point:

```php
$app = new Application([
    'debug' => true,
    'env' => 'dev',
    'project_dir' => __DIR__,
]);
```

It manages:
- Extension registration
- Lifecycle states (setup, boot, ready)
- Debug mode and environment detection

### Extensions

Extensions are modular components that extend the framework:

```php
$app->install(RouterConfig::class);
$app->install(ContainerExtension::class);
$app->install(ViewExtension::class);
```

Each extension provides:
- `install()` - Static installer called during app bootstrap
- `setup()` - Called during setup phase
- `boot()` - Called during boot phase
- `requires()` - Optional dependencies

### Dependency Injection Container

PHP-DI based container with autowiring:

```php
$container = $app->ext('container');

// Auto-resolve dependencies
$service = $container->get(MyService::class);

// Define explicit bindings
$container->define(MyInterface::class, MyImplementation::class);
```

### Middleware Pipeline

PSR-15 compliant middleware with priority ordering:

```php
$pipeline = $app->ext('pipeline');

$pipeline->pipe(new CorsMiddleware(), 100);
$pipeline->pipe(new AuthMiddleware(), 50);
$pipeline->pipe(new RouteMiddleware(), 0);
```

Lower priority numbers execute first (closer to the request).

### Router

FastRoute integration with route groups:

```php
$router->addRoute('/users/{id}', 'UserPage::show', ['GET']);
$router->addRoute('/posts/{id}', 'PostPage::show', ['GET']);

// Route groups
$router->addGroup('/api', function($router) {
    $router->addRoute('/users', 'ApiUserPage::index');
    $router->addRoute('/posts', 'ApiPostPage::index');
});
```

### Action Middleware Decorator

Executes page methods with dependency resolution:

```php
// Route: /users/{id}
// URL: /users/42

class UserPage
{
    public function show(int $id): Response
    {
        // $id = 42 (automatically cast to int)
        return new JsonResponse(['user_id' => $id]);
    }
}
```

## Directory Structure

```
src/
├── Application.php              # Main entry point
├── Extension/                    # Extension base classes
├── Container/                    # DI container
│   └── Executor/                 # Method invocation with DI
├── Router/                       # Routing system
│   ├── Router.php               # FastRoute implementation
│   ├── Route.php                # Route value object
│   ├── RouteResult.php          # Routing result
│   └── Middleware/              # Route middleware
├── Middleware/                   # PSR-15 middleware
│   ├── PipelineExtension.php    # Pipeline manager
│   └── MiddlewarePriorityPipe.php
├── View/                        # Template engines
│   ├── ViewExtension.php
│   ├── Plates/                  # League Plates support
│   └── Latte/                   # Latte support
├── Auth/                        # Authentication
│   ├── AuthenticationService.php
│   ├── Storage/                 # Session storage
│   └── Middleware/              # Auth middleware
├── Db/                          # Database
│   ├── DatabaseExtension.php
│   ├── Manager.php
│   ├── Command/                 # Migrations
│   └── Cycle/                   # Cycle ORM support
├── Event/                        # Events
├── Config/                       # Configuration
├── Session/                      # Sessions
├── Translation/                  # i18n
├── Console/                      # CLI
├── CMS/                          # CMS components
├── Discovery/                    # Class discovery
├── Logging/                      # Monolog
├── Maintenance/                  # Maintenance mode
├── Clockwork/                     # Debugging
└── FileRouting/                  # File-based routing
```

## Design Patterns

### 1. Extension Pattern

Modular components that can be installed/uninstalled:

```php
class MyExtension extends AbstractExtension
{
    public static function install(Application $app, ?array $options): mixed
    {
        // Called when extension is installed
        return true;
    }

    public function setup(): void
    {
        // Setup phase
    }

    public function boot(): void
    {
        // Boot phase
    }
}
```

### 2. Decorator Pattern

`ActionMiddlewareDecorator` wraps page execution with validation, auth, and permissions.

### 3. Factory Pattern

Extensions use factory classes for complex object creation:

```php
$executor = $container->get(Executor::class);
$renderer = $container->get(PlatesRenderer::class);
```

### 4. Strategy Pattern

Multiple template engine support (Plates, Latte) via `RendererInterface`.

### 5. Observer Pattern

Events system for hooking into framework lifecycle:

```php
$app->ext('events')->registerListener('app.ready', function($event) {
    // App is ready
});
```

## State Machine

The application follows a lifecycle:

```
BOOTING → SETUP → BOOTED → READY → RUNNING
```

Extensions can register callbacks for state transitions:

```php
$extension->when('ready', function() {
    // Called when app reaches ready state
});
```

## Error Handling

### Development Mode

Whoops integration provides detailed error pages:

```php
$app = new Application(['debug' => true]);
```

### Production Mode

Generic error responses with optional logging:

```php
$app = new Application(['debug' => false]);
// Returns 500 page, logs error
```

## Configuration

Configuration uses dot notation:

```php
$config->get('database.connections.default.host');
$config->set('app.debug', true);
```

Multiple config files by environment:

```
config/
├── app.php           # Base config
├── dev.php           # Development overrides
├── prod.php          # Production overrides
└── test.php          # Test overrides
```
