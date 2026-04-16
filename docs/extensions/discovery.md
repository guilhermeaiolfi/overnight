# Discovery Extension

The Discovery extension provides automatic class and attribute scanning for the Overnight framework. It scans configured directories for PHP files, extracts class metadata (attributes, annotations), and feeds that data to processors that register routes, event handlers, and other framework features automatically.

## Table of Contents

1. [Installation](#installation)
2. [How It Works](#how-it-works)
3. [Configuration](#configuration)
4. [Locations](#locations)
5. [Cache Strategies](#cache-strategies)
6. [Discoverers](#discoverers)
7. [Processors](#processors)
8. [Clearing the Cache](#clearing-the-cache)
9. [Creating Custom Discoverers](#creating-custom-discoverers)

---

## Installation

```php
use ON\Discovery\DiscoveryExtension;

DiscoveryExtension::install($app);
```

The extension requires the `container` and `config` extensions. It registers itself as `discovery` and is accessible via `$app->discovery`.

---

## How It Works

The discovery system has three phases:

1. **Recover** — Load previously cached discovery data from disk
2. **Update** — Scan the filesystem for files changed since the last cache, run discoverers on them
3. **Apply** — Feed the discovered data to processors that register routes, events, etc.

```
Locations (directories to scan)
    ↓
Cache Adapter (recover cached data)
    ↓
Filesystem scan (find new/changed files)
    ↓
Discoverers (extract metadata from files)
    ↓
Cache Adapter (save updated data)
    ↓
Processors (register routes, events, etc.)
```

Each location has its own cache strategy. Each discoverer produces its own data set. Processors consume the discovered data and wire it into the framework.

---

## Configuration

Discovery is configured via `AppConfig` under the `discovery` key:

```php
// config/app.php
use ON\Config\AppConfig;
use ON\Discovery\AttributesDiscoverer;
use ON\Discovery\DiscoveryLocation;
use ON\Discovery\CacheAdapter\TimestampCacheAdapter;
use ON\Discovery\CacheAdapter\AggressiveCacheAdapter;
use ON\Discovery\CacheAdapter\NeverCacheAdapter;

return [
    AppConfig::class => [
        'discovery' => [
            'cache_path' => 'var/cache/discovery/',

            'locations' => [
                new DiscoveryLocation(
                    name: 'app',
                    pattern: ['src/Pages'],
                    strategy: TimestampCacheAdapter::class
                ),
                new DiscoveryLocation(
                    name: 'vendor',
                    pattern: ['vendor/my-package/src/Pages'],
                    strategy: AggressiveCacheAdapter::class
                ),
            ],

            'discoverers' => [
                AttributesDiscoverer::class => [
                    'processors' => [
                        // Processors are registered by other extensions automatically
                    ],
                ],
            ],
        ],
    ],
];
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `cache_path` | string | `var/cache/discovery/` | Directory for cache files |
| `locations` | array | `[]` | Array of `DiscoveryLocation` objects |
| `discoverers` | array | `[]` | Map of discoverer class → config |

---

## Locations

A `DiscoveryLocation` defines where to scan and how to cache:

```php
new DiscoveryLocation(
    name: 'app',                              // Unique name (used in cache filenames)
    pattern: ['src/Pages', 'src/Controllers'], // Directories to scan (Symfony Finder patterns)
    strategy: TimestampCacheAdapter::class     // Cache strategy class
)
```

| Property | Type | Description |
|----------|------|-------------|
| `name` | string | Unique identifier for this location |
| `pattern` | array | Directory paths to scan (passed to Symfony Finder's `in()`) |
| `strategy` | string | Cache adapter class name |

You can define multiple locations with different cache strategies. For example, your app code uses `TimestampCacheAdapter` (re-scans changed files), while vendor code uses `AggressiveCacheAdapter` (scan once, cache forever).

---

## Cache Strategies

Cache adapters control when files are re-scanned:

### TimestampCacheAdapter (Recommended for development)

Compares file modification timestamps against the cache file timestamp. Only re-scans files that changed since the last cache was written.

```php
strategy: TimestampCacheAdapter::class
```

- First run: scans all files, creates cache
- Subsequent runs: only scans files modified after the cache timestamp
- Cache is invalidated per-file, not globally

### AggressiveCacheAdapter (Recommended for vendor/production)

Scans once and caches forever. Never re-scans unless the cache is manually cleared.

```php
strategy: AggressiveCacheAdapter::class
```

- First run: scans all files, creates cache
- Subsequent runs: loads from cache, never re-scans
- Use for vendor packages or production where files don't change

### NeverCacheAdapter (For testing)

Never caches. Scans all files on every request.

```php
strategy: NeverCacheAdapter::class
```

- Every run: scans all files from scratch
- Useful for testing or when you need guaranteed fresh data
- Not recommended for production (slow)

---

## Discoverers

A discoverer is a class that knows how to extract metadata from PHP files. The framework ships with one:

### AttributesDiscoverer

Scans PHP classes for PHP 8 attributes. Currently scans classes whose name ends with `Page` (e.g., `HomePage`, `LoginPage`).

The discovered attributes are fed to processors that register routes, event handlers, etc.

**How it works:**

1. `ClassFinder` tokenizes each PHP file to find class names (without loading them)
2. For classes matching the `*Page` pattern, `AttributeReader` extracts PHP attributes via reflection
3. Discovered attributes are stored as `DiscoveryItem` objects
4. On `apply()`, processors receive the collected attribute data

**Processors are registered by other extensions:**

- `EventsExtension` registers `EventHandlerAttributeProcessor` — discovers `#[EventHandler]` attributes
- `RouterExtension` registers `RouteAttributeProcessor` — discovers `#[Route]` attributes

You don't need to configure processors manually — they're added when you install the corresponding extensions.

---

## Processors

Processors consume discovered data and wire it into the framework. They're callables that receive an `AttributeReader`:

```php
class MyAttributeProcessor
{
    public function __construct(
        protected Application $app,
        protected array $options = []
    ) {
    }

    public function __invoke(AttributeReader $reader): void
    {
        // Process discovered attributes
        foreach ($reader->getCache() as $className => $attributes) {
            // Register routes, events, services, etc.
        }
    }
}
```

Register a processor in your extension's `boot()`:

```php
public function onConfigSetup(): void
{
    $appCfg = $this->app->config->get(AppConfig::class);
    $appCfg->set(
        "discovery.discoverers." . AttributesDiscoverer::class . ".processors." . MyProcessor::class,
        [] // options
    );
}
```

---

## Clearing the Cache

Clear all discovery caches programmatically:

```php
$app->discovery->clear();
```

Or via the console (if the console extension is installed):

```bash
php console cache:clear
```

---

## Creating Custom Discoverers

Implement `DiscoverInterface` to create a discoverer for your own file format or convention:

```php
use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryItem;
use ON\Discovery\DiscoveryItems;
use ON\Discovery\DiscoveryLocation;

class YamlConfigDiscoverer implements DiscoverInterface
{
    protected DiscoveryItems $items;

    public function __construct()
    {
        $this->items = new DiscoveryItems();
    }

    public function discover($file, DiscoveryLocation $location): void
    {
        if ($file->getExtension() !== 'yaml') {
            return;
        }

        $data = yaml_parse_file($file->getRealPath());

        $item = new DiscoveryItem($data, $location);
        $item->setFile($file->getRealPath());
        $this->items->add($item);
    }

    public function apply(): bool
    {
        foreach ($this->items as $item) {
            // Process YAML config data
        }
        return true;
    }

    public function getData(): mixed
    {
        return $this->items;
    }

    public function setData(mixed $data): void
    {
        $this->items = $data;
    }

    public function addData(mixed $data): void
    {
        foreach ($data as $item) {
            $this->items->add($item);
        }
    }
}
```

Register it in config:

```php
'discoverers' => [
    AttributesDiscoverer::class => ['processors' => []],
    YamlConfigDiscoverer::class => [],
],
```

---

## See Also

- [Events Extension](events.md) — Event handler attribute discovery
- [Routing Extension](routing.md) — Route attribute discovery
- [File Routing Extension](file-routing.md) — File-based route discovery
