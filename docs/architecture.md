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
- Extension registration (from `config/extensions.php` or array)
- Lifecycle phases: register (collect subscriptions) → resolve order (auto-inferred) → start (executed)
- Debug mode and environment detection
- Cached lifecycle order in `var/cache/app_lifecycle.php`

### Extensions

Extensions are modular components that extend the framework:

```php
$app->install(RouterExtension::class);
$app->install(ContainerExtension::class);
$app->install(ViewExtension::class);
```

Each extension provides:
- `install()` — Static installer called during app bootstrap.
- `register()` — Called during the registration pass. Subscribe to init events here using typed event class objects.
- `start()` — Called in auto-resolved dependency order during the start phase.
- Dependency order is automatically inferred from event subscription namespaces — no manual `requires()` needed.

**Service Registration:** Register services into the container by listening to typed event objects (e.g. `ConfigConfigureEvent::class`) during `register()`.

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
├── Auth/                        # Authentication/Authorization
│   ├── AuthenticationService.php
│   ├── Storage/                 # Session storage
│   └── Middleware/              # Auth middleware
├── Cache/                       # Cache management (Symfony Cache)
├── Clockwork/                    # Debug profiling
├── CMS/                         # CMS components
├── Common/                      # Shared traits
├── Config/                      # Configuration
├── Console/                     # CLI commands
├── Container/                   # DI container
│   └── Executor/                # Method invocation with DI
├── DB/                          # Database abstraction layer
│   ├── DatabaseExtension.php
│   ├── PdoDatabase.php, CycleDatabase.php, LaminasDbDatabase.php, Doctrine2Database.php
│   ├── Command/                 # Migrations
│   └── Cycle/                   # Cycle ORM support
├── Discovery/                   # Class discovery
├── Event/                       # Events
├── Exception/                   # Framework exceptions
├── Extension/                   # Extension base classes
├── FS/                          # Filesystem path management
│   ├── PathRegistry.php
│   └── PublicAssetManager.php
├── FileRouting/                 # File-based directory routing
├── GraphQL/                     # GraphQL API support
├── Handler/                     # Request handlers
├── Http/                        # HTTP utilities
├── Image/                       # Image processing
├── Init/                        # Init system and event lifecycle
├── Logging/                     # Monolog
├── Maintenance/                 # Maintenance mode
├── Middleware/                   # PSR-15 middleware
├── ORM/                         # Cycle ORM wrapper + definition system
├── PhpDebugBar/                 # PHP Debug Bar
├── RateLimit/                   # Rate limiting
├── RequestStack.php             # Request stack
├── Response/                    # Response utilities
├── RestApi/                     # REST API endpoints
├── Router/                      # Routing system
├── Service/                     # Service loaders
├── Session/                     # Sessions
├── Swoole/                      # Swoole integration
├── Translation/                 # i18n
└── View/                        # Template engines
    ├── Plates/                  # League Plates support
    └── Latte/                   # Latte support
```

## Design Patterns

### 1. Extension Pattern

Modular components that can be installed/uninstalled:

```php
class MyExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        // Register event listeners. Dependencies auto-inferred from namespaces.
    }

    public function start(InitContext $ctx): void
    {
        // Start phase — called in resolved dependency order
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

Events system for hooking into framework lifecycle. Overnight uses typed event objects:

- **Init Events**: Dispatched during `register()`/`start()`. Listened to by class name (e.g., `ConfigConfigureEvent::class`).
- **Domain Events**: Specific extension events (e.g., `RouterSetupEvent`, `OrmConfigureEvent`).

```php
// Registering a listener during register()
$init->on(ConfigConfigureEvent::class, function(ConfigConfigureEvent $event) {
    // $event->config is typed and auto-resolved
});
```

Event objects can be dispatched by passing an instance to `emit()` — the class name becomes the event key automatically:

## State Machine & Initialization

The framework uses a five-phase initialization managed by the `Init` system:

```
INSTANTIATE (all extensions) → REGISTER (collect subscriptions) → RESOLVE ORDER (topological sort) → SORT (listener priority) → START (in resolved order)
```

### Phase 1: Instantiation

Every extension is instantiated via `new $class($app, $options)`. No event subscriptions happen yet.

### Phase 2: Registration

Every extension calls `register()` on the `Init` object. During this phase, the `Init` tracks which extension owns each event subscription via `$init->setCurrentExtension()`:

```php
public function register(Init $init): void
{
    // Typed event objects — pass ::class string or an instance
    $init->on(ConfigConfigureEvent::class, function(ConfigConfigureEvent $event) {
        // Register services
    });
}
```

### Phase 2: Order Resolution

After all extensions register, `Application::rebuildLifecycleOrder()` builds a dependency graph from the subscription map. Extensions that listen to events in another extension's namespace are ordered after that extension. The result is cached in `var/cache/app_lifecycle.php` (production) or recalculated on every request (debug).

### Phase 3: Start Execution

Extensions' `start()` methods are called in the resolved order. Use `$this->app->init()->context()` to emit events:

```php
public function start(InitContext $ctx): void
{
    // Emit a typed event — pass an instance
    $ctx->emit(new MyExtensionReadyEvent($this));
}
```

### Event Objects

Events can be dispatched as typed objects — the class name becomes the event name:

```php
// Emit:
$init->emit(new ConfigConfigureEvent($config));

// Listen:
$init->on(ConfigConfigureEvent::class, function(ConfigConfigureEvent $event) {
    // $event->config is typed
});
```

This replaces the older enum/string-based event system. When `emit()` receives a single object (no explicit payload), the object itself serves as both the event identifier (via `get_class()`) and the payload.

### Initialization Debugging

The framework tracks all emitted events in an `eventHistory`. Access it through `InitContext`:

```php
$history = $app->init()->getEventHistory();

foreach ($history as $entry) {
    echo $entry['event'] . " emitted\n";
}
```

### Subscription Map

Inspect which extensions subscribe to which events via `$app->init()->getSubscriptionMap()`:

```php
$map = $app->init()->getSubscriptionMap();
// [ExtensionClass => [EventName, ...]]
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

Multiple config files by environment (loaded via glob pattern `config/{,/*.}{all,{env},local}.php`):

```
config/
├── all.php              # Shared base config
├── dev.php              # Development overrides
├── prod.php             # Production overrides
├── local.php            # Local overrides (not committed)
├── database/all.php     # Database module config
├── database/dev.php     # Database dev overrides
├── orm.all.php          # ORM registry definitions
└── .../
```
