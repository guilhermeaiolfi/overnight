# Configuration

Overnight uses a flexible configuration system with dot notation and environment-based loading.

## Basic Usage

```php
$config = $app->ext('config');

// Get value
$debug = $config->get('app.debug');

// Set value
$config->set('app.debug', true);

// Check existence
if ($config->has('database.host')) {
    // ...
}
```

## Dot Notation

Access nested arrays with dot notation:

```php
// These are equivalent
$config->get('database.host');
$config->get('database')['host'];
$config['database']['host'];

// Nested access
$config->get('cache.drivers.file.path');
$config->get('auth.providers.session.timeout');
```

## Configuration Files

### Directory Structure

```
config/
├── all.php              # Shared base config
├── dev.php              # Development overrides (when env=dev)
├── prod.php             # Production overrides (when env=prod)
├── local.php            # Local overrides (not committed to VCS)
├── database/all.php     # Module-level config
├── database/dev.php     # Module-level dev overrides
├── orm.all.php          # ORM Registry definitions
└── .../
```

### Loading by Environment

```php
$app = new Application([
    'env' => 'dev',  // Loads config/dev.php overrides
    // or
    'env' => 'prod', // Loads config/prod.php overrides
]);
```

The config loader scans files matching the pattern `config/{,/*.}{all,{env},local}.php`. Files are loaded in order, with later files overriding earlier ones:

- `config/all.php` — Shared base config
- `config/{env}.php` — Environment-specific (e.g. `dev.php`)
- `config/local.php` — Local overrides (not committed to VCS)
- `config/*/all.php` — Module base config files
- `config/*/{env}.php` — Module environment-specific
- `config/*/local.php` — Module local overrides

### Config Files

```php
<?php
// config/app.php

return [
    'name' => 'My Application',
    'debug' => false,
    'timezone' => 'UTC',
    
    'paths' => [
        'root' => dirname(__DIR__),
        'public' => dirname(__DIR__) . '/public',
        'storage' => dirname(__DIR__) . '/storage',
    ],
];
```

```php
<?php
// config/dev.php

return [
    'debug' => true,
    'cache' => [
        'enabled' => false,
    ],
];
```

### Non-Serializable Objects (Cache Exceptions)

Configuration files can return any PHP object (e.g., a Database connection, an ORM Registry, or a Closure). While most configuration is cached for performance, objects that cannot be serialized are treated as "Cache Exceptions".

When a configuration file returns a non-serializable object:
1. The object is stored in the `Config` instance.
2. The file path is tracked by the framework.
3. If configuration caching is enabled, these specific files are re-included and their objects re-hydrated on every request, even when the rest of the configuration is loaded from the cache.

This allows you to maintain the performance of a cached configuration while still supporting dynamic or non-serializable service definitions.

```php
<?php
// config/orm.all.php

use ON\ORM\Definition\Registry;

// This Registry object will be re-loaded on every request
// even if the config is cached.
return new Registry();
```

## Config Class

### Dot Class

The `Dot` class provides nested array access:

```php
use ON\Config\Dot;

$config = new Dot([
    'database' => [
        'default' => [
            'host' => 'localhost',
            'port' => 3306,
        ],
    ],
]);

// Get with default
$host = $config->get('database.default.host', 'localhost');

// Set values
$config->set('app.name', 'My App');

// Check existence
if ($config->has('database.default.host')) {
    // ...
}

// Delete
$config->delete('some.key');

// Check if empty
if ($config->isEmpty('cache')) {
    // ...
}
```

### Array Operations

```php
// Merge values
$config->merge('plugins', ['plugin1', 'plugin2']);

// Recursive merge
$config->mergeRecursive('settings', [
    'theme' => ['color' => 'blue'],
]);

// Push to array
$config->push('plugins', 'new-plugin');

// Pull (get and delete)
$value = $config->pull('temporary.value');

// Flatten
$flat = $config->flatten('database');
// ['database.host' => 'localhost', 'database.port' => 3306]
```

### Array Access

```php
$config = new Dot($data);

// Bracket access
echo $config['app.name'];

// isset
isset($config['app.debug']);

// Array iteration
foreach ($config as $key => $value) {
    // ...
}
```

## AppConfig

Application-specific configuration:

```php
use ON\Config\AppConfig;

// Access framework config
$config = $app->ext('config');

// Framework-specific settings
$debug = $config->get('app.debug');
$env = $config->get('app.env');
$timezone = $config->get('app.timezone');

// Extensions
$config->get('extensions.router.enabled');
```

## Environment Variables

### Loading .env

The framework auto-loads `.env` files:

```bash
# .env
APP_DEBUG=true
APP_ENV=dev
DATABASE_URL="mysql://root:password@localhost/myapp"
```

### Custom .env Path

```php
$app = new Application([
    'dotenv_path' => __DIR__ . '/.env',
]);
```

### In Config Files

```php
<?php
// config/database.php

return [
    'host' => env('DB_HOST', 'localhost'),
    'port' => env('DB_PORT', 3306),
    'database' => env('DB_NAME'),
    'username' => env('DB_USER'),
    'password' => env('DB_PASS'),
];
```

## Service Configuration

### Database

```php
<?php
// config/database.php

return [
    'default' => 'mysql',
    
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_NAME'),
            'username' => env('DB_USER', 'root'),
            'password' => env('DB_PASS', ''),
            'charset' => 'utf8mb4',
        ],
        
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('SQLITE_DATABASE', database_path('app.db')),
        ],
    ],
];
```

### Cache

```php
<?php
// config/cache.php

return [
    'default' => 'file',
    
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('cache'),
        ],
        
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
    ],
];
```

### Session

```php
<?php
// config/session.php

return [
    'driver' => 'native',
    'lifetime' => 120,
    'name' => 'overnight_session',
    'path' => '/',
    'domain' => null,
    'secure' => false,
    'httponly' => true,
];
```

### Extension Configuration & Service Registration

Extensions receive configuration during install, but the preferred way to register services into the container is by listening to the `ConfigConfigureEvent` event. This ensures that service definitions are correctly processed and can be overridden by user configuration.

```php
use ON\Init\Init;
use ON\Config\Init\Event\ConfigConfigureEvent;

class MyExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        $init->on(ConfigConfigureEvent::class, function(ConfigConfigureEvent $event) {
            $config = $event->config;
            
            // Register a service in the container
            $config->set('container.my_service', function($container) {
                return new MyService();
            });
        });
    }
}
```

Service registrations using `ConfigConfigureEvent` are automatically included in the configuration cache unless they return non-serializable objects.

## Caching Config

In production, the framework automatically caches your configuration if enabled in the `ConfigExtension`. You typically don't need to implement manual caching.

The cache includes:
- All static configuration arrays.
- Service definitions registered via `ConfigConfigureEvent`.
- Tracked file paths for "Cache Exceptions" (non-serializable objects).

To clear the cache, you can delete the `var/cache/config.php` file.

## Best Practices

1. **Use environment variables** - Don't hardcode sensitive values
2. **Provide defaults** - Always have sensible defaults
3. **Organize by feature** - Separate into logical files
4. **Document config keys** - Comment non-obvious settings
5. **Validate early** - Check required config on app boot
6. **Cache in production** - Avoid file I/O on every request
