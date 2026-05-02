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
use ON\Router\RouterConfig;

$app = new Application([
    'debug' => true,
    'env' => 'dev',
]);

$app->install(RouterConfig::class);
$app->install(ContainerExtension::class);
$app->install(ViewExtension::class);

$router = $app->ext('router');
$router->addRoute('/hello/{name}', 'HelloPage::greet', ['GET']);

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
- [Routing](routing.md) - Routes, parameters, and URL generation
- [Controllers](controllers.md) - Pages with auto-injected route parameters

### Core Concepts
- [Middleware](middleware.md) - PSR-15 middleware pipeline
- [Dependency Injection](di-container.md) - Container and parameter resolution
- [Views](views.md) - Template rendering with Plates/Latte
- [Configuration](configuration.md) - Config system and dot notation

### Features
- [Authentication](auth.md) - Auth service, authenticators, guards
- [Database](database.md) - ORM, migrations, queries
- [Events](events.md) - Event dispatcher system
- [CLI](cli.md) - Console commands
- [Extensions](extensions.md) - Framework extension system

### Reference
- [Testing](testing.md) - Writing tests
- [API Reference](api/) - Detailed class documentation

## Directory Structure

```
src/
├── Application.php          # Main application class
├── Container/                # DI container and executors
├── Init/                     # Init system and event lifecycle
├── Extension/                # Extension base classes & interface
├── Router/                   # Routing system
├── Middleware/               # PSR-15 middleware
├── View/                     # Template engines
├── Auth/                     # Authentication/Authorization
├── Db/                       # Database/ORM/Migrations
├── Event/                    # Event system
├── Session/                  # Session handling
├── Translation/              # i18n support
├── Config/                   # Configuration system
├── Discovery/                # Class discovery via attributes
├── Console/                  # CLI commands
├── CMS/                      # Built-in CMS components
├── Logging/                  # Logging (Monolog)
├── Maintenance/              # Maintenance mode
├── Clockwork/                # Debugging
├── FileRouting/              # File-based routing
├── Image/                    # Image processing
├── RateLimit/                # Rate limiting
├── RestApi/                  # REST API endpoints
├── GraphQL/                  # GraphQL API support
└── AutoWiring/               # Auto-wiring extension discovery
```

## Requirements

- PHP 8.1+
- PSR-7 (HTTP messages)
- PSR-11 (Container)
- PSR-15 (Middleware)

## License

MIT
