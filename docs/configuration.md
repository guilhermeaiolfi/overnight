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
├── app.php           # Base application config
├── dev.php           # Development overrides
├── prod.php          # Production overrides
├── test.php          # Test overrides
├── database.php      # Database config
├── cache.php         # Cache config
├── mail.php          # Mail config
└── services.php      # Service definitions
```

### Loading by Environment

```php
$app = new Application([
    'env' => 'dev',  // Loads config/dev.php overrides
    // or
    'env' => 'prod', // Loads config/prod.php overrides
]);
```

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

## Merging Configs

### From Multiple Sources

```php
use ON\Config\ConfigBuilder;

$builder = new ConfigBuilder();

// Load base
$builder->addConfig($baseConfig);

// Load environment-specific
$builder->addConfig($envConfig);

// Add overrides
$builder->add('debug', true);

$config = $builder->build();
```

### Extension Configuration

Extensions receive configuration during install:

```php
class MyExtension extends AbstractExtension
{
    public static function install(Application $app, ?array $options): mixed
    {
        // Access config
        $config = $app->ext('config');
        
        // Or receive options
        $path = $options['path'] ?? 'default/path';
        
        return true;
    }
}

// Install with options
$app->install(MyExtension::class, [
    'path' => '/custom/path',
]);
```

## Caching Config

In production, cache your configuration:

```php
<?php
// config/bootstrap.php

$config = new Dot();

if ($app->isProduction()) {
    $cachePath = __DIR__ . '/cache/config.php';
    
    if (file_exists($cachePath)) {
        $config = unserialize(file_get_contents($cachePath));
    } else {
        // Build and cache
        $config = buildConfig();
        file_put_contents($cachePath, serialize($config));
    }
} else {
    $config = buildConfig();
}

return $config;
```

## Best Practices

1. **Use environment variables** - Don't hardcode sensitive values
2. **Provide defaults** - Always have sensible defaults
3. **Organize by feature** - Separate into logical files
4. **Document config keys** - Comment non-obvious settings
5. **Validate early** - Check required config on app boot
6. **Cache in production** - Avoid file I/O on every request
