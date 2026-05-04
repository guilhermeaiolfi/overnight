# Auto-Wiring Extension

`AutoWiringExtension` discovers application extensions from a project directory and installs them during application bootstrap.

## Installation

Add the extension to your `config/extensions.php` file:

```php
<?php

use ON\Extension\AutoWiringExtension;

return [
    AutoWiringExtension::class => [
        'scan_path' => 'modules',
    ],
];
```

The default scan path is `modules`, relative to the project root.

## Module Structure

Any non-abstract class under the scan path that implements `ON\Extension\ExtensionInterface` is installed automatically.

```php
<?php

namespace App\Modules\Blog;

use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;

final class BlogExtension extends AbstractExtension
{
    public static function install(Application $app, ?array $options = []): ?ExtensionInterface
    {
        $extension = new self($app, $options ?? []);
        $app->registerExtension('blog', $extension);

        return $extension;
    }
}
```

## Options

```php
AutoWiringExtension::class => [
    'scan_path' => 'modules',
    'cache' => 'auto',
    'cache_path' => 'var/cache',
    'exclude' => [
        '*/modules/Base/*',
    ],
    'extensions' => [
        App\Modules\Blog\BlogExtension::class => [
            'enabled' => true,
            'posts_per_page' => 10,
        ],
    ],
],
```

Supported options:

- `scan_path`: A directory to scan. Defaults to `modules`.
- `scan_paths`: Multiple directories to scan.
- `exclude`: File or class patterns to skip. `*` wildcards are supported.
- `extensions`: Per-extension options passed to the discovered extension's `install()` method.
- `enabled`: Per-extension boolean or callable. Disabled extensions are skipped.
- `cache`: `auto`, `true`, or `false`. Defaults to `auto`.
- `cache_path`: Directory for the filesystem discovery cache. Defaults to `var/cache`.
- `cache_file`: Exact cache file path. Overrides `cache_path`.

When `cache` is `auto`, discovery is cached only when the application is not in debug mode. Set `cache` to `true` to always cache, or `false` to always scan.

Aliases are not configured by auto-wiring. Each extension should register its own aliases from its `install()` method.

If an auto-wired extension is already installed manually, bootstrap fails with a duplicate extension error.
