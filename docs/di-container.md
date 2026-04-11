# Dependency Injection Container

Overnight uses PHP-DI for dependency injection with automatic resolution.

## Basic Usage

### Getting Services

```php
$container = $app->ext('container');

// By type
$service = $container->get(MyService::class);

// Check if exists
if ($container->has(MyService::class)) {
    $service = $container->get(MyService::class);
}
```

### Auto-Wiring

The container automatically resolves constructor dependencies:

```php
class UserController
{
    public function __construct(
        private UserRepository $users,
        private EmailService $email
    ) {}
}

// Container auto-resolves UserRepository and EmailService
$controller = $container->get(UserController::class);
```

## Definitions

### Defining Services

```php
// By closure
$container->define(MyService::class, function($container) {
    return new MyService(
        $container->get(Dependency::class)
    );
});

// By factory method
$container->define(MyService::class, [MyFactory::class, 'create']);

// As singleton (default)
$container->define(MyService::class)->scope(Scope::SINGLETON);
```

### Interfaces to Implementations

```php
// Bind interface to implementation
$container->define(LoggerInterface::class, MonologLogger::class);

// Bind to instance
$container->define(LoggerInterface::class, $monologInstance);

// Bind with factory
$container->define(LoggerInterface::class, function($container) {
    return new MonologLogger('app');
});
```

### Aliases

```php
$container->alias('logger', LoggerInterface::class);
$container->alias('log', LoggerInterface::class);

// Now you can get by alias
$logger = $container->get('log');
```

## Parameter Injection

### Constructor Injection

```php
class Database
{
    public function __construct(
        string $host,
        int $port = 3306,
        ?string $database = null
    ) {}
}

// Define with parameters
$container->define(Database::class, [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'myapp'
]);
```

### Property Injection

```php
class Service
{
    #[Inject]
    public LoggerInterface $logger;
}
```

### Method Injection

```php
class Service
{
    public function setLogger(#[Inject] LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
```

## Controller/Page Parameter Resolution

The framework automatically resolves method parameters in controllers:

### Resolution Order

1. **Route parameters** - From URL patterns (by name)
2. **Container services** - By type hint (PSR types, other services)
3. **Provided parameters** - Passed explicitly to executor
4. **Default values** - For optional parameters

### Example

```php
class UserPage
{
    public function show(
        int $id,                           // Route param (auto-cast to int)
        ServerRequestInterface $request,   // PSR type from container
        LoggerInterface $logger,           // Service from container
        string $format = 'json'            // Default value
    ): Response {
        // All parameters automatically resolved
    }
}
```

### Type Casting

Route parameters are automatically cast:

| Type | URL Value | Resolved As |
|------|-----------|-------------|
| `int` | `"42"` | `42` |
| `float` | `"3.14"` | `3.14` |
| `bool` | `"1"` | `true` |
| `string` | `"hello"` | `"hello"` |
| (none) | `"42"` | `"42"` |

## Executor

The `Executor` class invokes callables with automatic DI:

```php
use ON\Container\Executor\Executor;

$executor = $container->get(Executor::class);

// Simple call
$result = $executor->execute([$controller, 'index']);

// With parameters
$result = $executor->execute([$controller, 'show'], [
    'id' => 42
]);

// Callable
$result = $executor->execute(function (LoggerInterface $logger) {
    $logger->info('Called!');
});
```

### Parameter Resolvers

The executor uses a chain of resolvers:

1. **TypeHintResolver** - Resolve by type hints
2. **NumericArrayResolver** - Resolve positional arrays
3. **AssociativeArrayResolver** - Resolve named arrays
4. **DefaultValueResolver** - Use parameter defaults
5. **TypeHintContainerResolver** - Get from container

## Container Configuration

```php
$config = $app->ext('container');

// Add factory
$config->addFactory(MyService::class, MyServiceFactory::class);

// Add factories
$config->addFactories([
    ServiceA::class => FactoryA::class,
    ServiceB::class => FactoryB::class,
]);

// Add aliases
$config->addAlias('service.a', ServiceA::class);
$config->addAliases([
    'logger' => LoggerInterface::class,
    'cache' => CacheInterface::class,
]);
```

## Scopes

### Singleton (default)

Same instance returned every time:

```php
$container->define(MyService::class)->scope(Scope::SINGLETON);
```

### Prototype

New instance created each time:

```php
$container->define(MyService::class)->scope(Scope::PROTOTYPE);
```

## Environment Configuration

Configure services differently per environment:

```php
if ($app->getEnvironment() === 'dev') {
    $container->define(DebugBar::class, function($container) {
        return new DebugBar(true);
    });
}
```

## Best Practices

1. **Prefer constructor injection** - Makes dependencies explicit
2. **Use interfaces** - Bind interfaces to implementations for flexibility
3. **Avoid service locator** - Don't use `$container->get()` inside services
4. **Keep containers focused** - Define services close to where they're used
5. **Use autowiring** - Let the container resolve dependencies automatically
