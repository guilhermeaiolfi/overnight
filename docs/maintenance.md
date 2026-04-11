# Maintenance Mode

Overnight provides maintenance mode that returns a 503 response to all visitors, with optional IP whitelisting for developers.

## Configuration

Install the extension:

```php
use ON\Maintenance\MaintenanceExtension;

$app->install(new MaintenanceExtension());
```

## Basic Usage

### CLI Command

```bash
# Enable maintenance mode
php bin/console on:maintenance on

# Disable maintenance mode
php bin/console on:maintenance off
```

### Environment Variable

You can also manually set the variable:

```bash
# In .env file
APP_MAINTENANCE=true
```

## IP Whitelisting

Allow specific IP addresses to bypass maintenance mode and view the site normally.

### Configuration

**Via environment variable:**

```bash
# .env
APP_MAINTENANCE=true
MAINTENANCE_ALLOW_IPS=127.0.0.1,192.168.1.100
```

**Via config file:**

```php
// In your config
$config->set('maintenance.allow_ips', '127.0.0.1,192.168.1.100');
```

### Supported IP Formats

| Format | Example | Description |
|--------|---------|-------------|
| Exact IP | `192.168.1.1` | Single IP address |
| Wildcard | `192.168.1.*` | Match any IP in range |
| CIDR | `10.0.0.0/8` | Match IP range using CIDR notation |
| Multiple | `1.2.3.4,5.6.7.8` | Comma-separated list |

### Examples

**Allow localhost and specific IP:**

```bash
MAINTENANCE_ALLOW_IPS=127.0.0.1,192.168.1.100
```

**Allow entire subnet (CIDR):**

```bash
# Allow all IPs in 192.168.x.x range
MAINTENANCE_ALLOW_IPS=192.168.0.0/16
```

**Allow local network:**

```bash
MAINTENANCE_ALLOW_IPS=127.0.0.1,10.0.0.0/8,172.16.0.0/12
```

**Wildcard matching:**

```bash
# Allow any IP starting with 192.168.1
MAINTENANCE_ALLOW_IPS=192.168.1.*
```

## Checking Status

```php
$maintenance = $app->ext('maintenance');

if ($maintenance->isMaintenanceMode()) {
    // App is in maintenance mode
}
```

## Custom Maintenance Page

Create a `template.html` file in your public/web root:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Maintenance</title>
    <style>
        body {
            font-family: sans-serif;
            text-align: center;
            padding: 50px;
        }
    </style>
</head>
<body>
    <h1>We'll be back soon!</h1>
    <p>We're currently performing scheduled maintenance.</p>
</body>
</html>
```

## Custom Maintenance Handler

Override the maintenance controller:

```php
// In your config
$config->set('controllers.maintenance', 'MyMaintenancePage::handle');
```

```php
class MyMaintenancePage
{
    public function handle(): Response
    {
        return new HtmlResponse('<h1>Maintenance</h1><p>Back soon!</p>', 503);
    }
}
```

## How It Works

1. `MaintenanceMiddleware` runs at priority 900 (early in pipeline)
2. Checks `$app->isMaintenanceMode()`
3. If true, checks if client IP is whitelisted
4. If IP is whitelisted, passes request to next handler (site works normally)
5. If IP is not whitelisted, executes maintenance controller
6. Returns 503 response

## Best Practices

1. **Always whitelist your IP** - Before enabling maintenance, add your IP to `MAINTENANCE_ALLOW_IPS`
2. **Use CIDR for teams** - Use subnets like `10.0.0.0/8` for entire office networks
3. **Test locally first** - Ensure `127.0.0.1` is in your whitelist during development
4. **Disable after deploy** - Remember to run `php bin/console on:maintenance off` when done
