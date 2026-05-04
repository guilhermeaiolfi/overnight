# Overnight Framework

A modern PHP framework with PSR-7/PSR-15 compliance, dependency injection, and powerful routing.

## Features

- **PSR-7/PSR-15 Compliant** - Full HTTP message and middleware support
- **Dependency Injection** - PHP-DI based container with autowiring
- **Powerful Routing** - FastRoute integration with route groups and file-based routing
- **Template Engines** - Support for Plates and Latte
- **Authentication** - Built-in auth with session storage
- **ORM** - Cycle ORM with migrations
- **Events** - Typed event object system with auto-ordered lifecycle
- **i18n** - Full translation support
- **CLI** - Symfony Console integration with `db:migrate:all` command
- **Debugging** - Clockwork profiling, Whoops error pages
- **REST API** - Auto-generated Directus-style REST endpoints from entity definitions
- **GraphQL** - Auto-generated GraphQL API from entity definitions
- **Rate Limiting** - Built-in rate limit support
- **Maintenance Mode** - Built-in maintenance mode
- **Image Processing** - Image manipulation support

## Quick Start

### Installation

```bash
composer install
```

### Basic Application

```php
<?php
require 'vendor/autoload.php';

use ON\Application;

$app = new Application([
    'debug' => true,
    'env' => 'dev',
]);

// Extensions are loaded from config/extensions.php by default
// Alternatively, add them to the options:
$app = new Application([
    'debug' => true,
    'env' => 'dev',
    'extensions' => [
        \ON\Config\ConfigExtension::class => [],
        \ON\Container\ContainerExtension::class => [],
        \ON\Router\RouterExtension::class => [],
        \ON\View\ViewExtension::class => [],
    ],
]);

$router = $app->ext('router');
$router->get('/hello/{name}', function(string $name) {
    return new JsonResponse(['message' => "Hello, $name!"]);
});

$app->run();
```

### Creating Pages (Controllers)

```php
class HelloPage
{
    public function greet(string $name): Response
    {
        return new JsonResponse(['message' => "Hello, $name!"]);
    }
}
```

## Documentation

### Getting Started
- [Architecture](architecture.md) - How the framework is structured
- [Controllers](controllers.md) - Pages with auto-injected route parameters
- [Configuration](configuration.md) - Config system and dot notation

### Core Concepts
- [Middleware](middleware.md) - PSR-15 middleware pipeline
- [Extensions](extensions.md) - Framework extension system
- [CLI](cli.md) - Console commands
- [Testing](testing.md) - Writing tests

### Extension Modules
- [Routing](extensions/routing.md) - Routes, parameters, and URL generation
- [Dependency Injection](extensions/di-container.md) - Container and parameter resolution
- [Views](extensions/views.md) - Template rendering with Plates/Latte
- [Authentication](extensions/auth.md) - Auth service, authenticators, guards
- [Database](extensions/database.md) - ORM, migrations, queries
- [Events](extensions/events.md) - Event dispatcher system
- [Sessions](extensions/sessions.md) - Session management
- [Translation](extensions/translation.md) - i18n support
- [File Routing](extensions/file-routing.md) - File-based directory routing
- [GraphQL](extensions/graphql.md) - Auto-generated GraphQL API
- [REST API](extensions/rest-api.md) - Auto-generated REST endpoints
- [Image Processing](extensions/image.md) - Image manipulation
- [Auto-Wiring](extensions/auto-wiring.md) - Automatic extension discovery
- [Maintenance](extensions/maintenance.md) - Maintenance mode
- [Discovery](extensions/discovery.md) - Class discovery via attributes

### ORM / Entity Reference
- [ORM Entity Definition](orm-entity-definition.md) - Field types, relations, metadata
- [Action Middleware Decorator](action-middleware-decorator.md) - Dependency resolution in controllers
- [CRUD Module Guide](crud-module.md) - Building full-stack CRUD with GraphQL or REST

## Directory Structure

```
src/
├── Application.php          # Main application class
├── Auth/                    # Authentication/Authorization
├── Benchmark.php            # Benchmarking utility
├── Cache/                   # Cache management (Symfony Cache)
├── Clockwork/               # Debug profiling (Clockwork)
├── CMS/                     # Built-in CMS components
├── Common/                  # Shared traits (AttributesTrait)
├── Config/                  # Configuration system
├── Console/                 # CLI commands (Symfony Console)
├── Container/               # DI container and executors (PHP-DI)
├── Data.php                 # Data helper
├── DB/                      # Database abstraction (Cycle, PDO, Laminas, Doctrine)
├── Discovery/               # Attribute-based class discovery
├── Event/                   # Event dispatcher (League Event)
├── Exception/               # Framework exceptions
├── Extension/               # Extension base classes & interface
├── FileRouting/             # File-based directory routing
├── FS/                      # Filesystem path management (PathRegistry, PublicAssetManager)
├── GraphQL/                 # GraphQL API schema generation & CRUD
├── Handler/                 # Request handlers (NotFoundHandler)
├── Http/                    # HTTP utilities (RequestContext)
├── Image/                   # Image processing (Intervention)
├── Init/                    # Init system and event lifecycle
├── Logging/                 # Logging (Monolog)
├── Maintenance/             # Maintenance mode
├── Middleware/              # PSR-15 middleware pipeline
├── MiddlewareContainer.php  # Middleware container
├── MiddlewareFactory.php    # Middleware factory
├── MiddlewareFactoryInterface.php
├── ORM/                     # Cycle ORM wrapper with definition system
├── PhpDebugBar/             # PHP Debug Bar integration
├── RateLimit/               # Rate limiting
├── RequestStack.php         # Request stack
├── RequestStackInterface.php
├── Response/                # Response utilities
├── RestApi/                 # Auto-generated REST API endpoints
├── Router/                  # Routing system (FastRoute)
├── Service/                 # Service loaders (EnvLoader, RoutesLoader)
├── Session/                 # Session handling
├── Swoole/                  # Swoole integration
├── Translation/             # i18n support
└── View/                    # Template engines (Plates, Latte)
```

## Requirements

- PHP 8.1+
- PSR-7 (HTTP messages)
- PSR-11 (Container)
- PSR-15 (Middleware)

## License

MIT
