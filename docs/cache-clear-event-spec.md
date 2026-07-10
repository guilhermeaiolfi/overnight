# Cache Clearer Registration Event Spec

## Status

Draft for review.

## Problem

`cache:clear` currently knows about cache owners directly:

- `CacheInterface`
- `DiscoveryExtension`
- the application cache directory

That makes the console command responsible for framework-specific cache knowledge and forces every new cache owner to modify `ClearCacheCommand`. Extensions such as discovery, file routing, image processing, ORM/database schema generation, config caching, and auto-wiring should be able to declare their own cache clear behavior.

## Goals

- Let extensions register named cache clearers through the cache extension.
- Keep `cache:clear` generic: it should clear entries from a registry, not know every extension.
- Support interactive selection, `--all`, and named non-interactive clearing.
- Keep clearers lazy where possible so command boot does not eagerly instantiate expensive services.
- Avoid non-CLI request overhead while cache clearing is only exposed through console commands.
- Preserve existing behavior for `CacheInterface`, discovery cache, and full application cache directory clearing.

## Non-Goals

- Replacing Symfony Cache or `CacheInterface`.
- Changing runtime cache reads/writes.
- Adding warmup/preload behavior in this pass.
- Moving request-scoped cleanup, such as GraphQL DataLoader cleanup, into `cache:clear`.

## Proposed API

### `ON\Cache\CacheClearerRegistry`

Mutable registry owned by `CacheExtension`.

```php
namespace ON\Cache;

final class CacheClearerRegistry
{
    public function add(CacheClearerDefinition $definition): void;

    public function has(string $name): bool;

    public function get(string $name): CacheClearerDefinition;

    /** @return array<string, CacheClearerDefinition> */
    public function all(): array;
}
```

### `ON\Cache\CacheClearerDefinition`

Small value object describing one clearable cache.

```php
namespace ON\Cache;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CacheClearerDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly callable $clear,
        public readonly int $priority = 0,
        public readonly bool $includedInAll = true,
        public readonly ?string $description = null,
    ) {
    }

    public function clear(ContainerInterface $container, OutputInterface $output): void;
}
```

The callable should accept the container and console output:

```php
fn (ContainerInterface $container, OutputInterface $output): void => ...
```

This keeps registrations lazy. A clearer can fetch its service from the container only when selected, after application bootstrap is complete.

### `ON\Cache\Init\Event\CacheClearersConfigureEvent`

Typed init event emitted by `CacheExtension` during its `start()` phase, but only when the application is running in CLI mode. The registry is owned by `CacheExtension` before the container exists; container consumers receive the same registry instance through `CacheClearerRegistryFactory`.

```php
namespace ON\Cache\Init\Event;

use ON\Application;
use ON\Cache\CacheClearerRegistry;

final class CacheClearersConfigureEvent
{
    public function __construct(
        public CacheClearerRegistry $registry,
        public Application $app,
    ) {
    }
}
```

Extensions listen to this event in `register(Init $init)`:

```php
$init->on(CacheClearersConfigureEvent::class, function (CacheClearersConfigureEvent $event): void {
    $event->registry->add(new CacheClearerDefinition(
        name: 'discovery',
        label: 'Discovery',
        clear: function (ContainerInterface $container): void {
            $this->clear();
        },
        priority: 100,
    ));
});
```

Extensions should guard the optional listener with `class_exists(CacheClearersConfigureEvent::class)` if they must keep working when the cache extension is moved out of the package.

## Lifecycle

1. `CacheExtension` creates a `CacheClearerRegistry` in its constructor.
2. `CacheExtension::register()` keeps registering cache services during `ConfigConfigureEvent`.
3. `CacheClearerRegistryFactory` exposes the CacheExtension-owned registry to the container.
4. In CLI mode, `CacheExtension::start()` registers framework-owned clearers:
   - `cache`: clears `CacheInterface`
   - `app-cache-dir`: clears `$app->paths->get('cache')`
5. `CacheExtension::start()` emits `CacheClearersConfigureEvent`.
6. Other extensions add their clearers from listeners.
7. Clearer callbacks resolve config/container/services at execution time, when bootstrap is complete.
8. `ClearCacheCommand` depends on `CacheClearerRegistry` and presents registered definitions.

This uses init lifecycle events, not the runtime `EventsExtension`, because the registry must be available before console commands run and should work even if the events extension is not installed.

Because there is currently no cache-clearing API outside `cache:clear`, this event should not run during normal web requests. If a future HTTP/admin/programmatic clearing surface is added, the condition can be widened from "CLI mode" to "cache clearing surface is enabled".

## CLI Behavior

### Interactive

`php bin/console cache:clear`

The command shows choices from `CacheClearerRegistry::all()`, sorted by priority descending and then label:

- `cache` - CacheInterface
- `discovery` - Discovery
- `app-cache-dir` - Application cache directory
- `all` - All

Selecting `all` clears every definition where `includedInAll` is true.

### Non-Interactive

Add options:

```bash
php bin/console cache:clear --all
php bin/console cache:clear cache discovery
php bin/console cache:clear --list
```

Recommended command signature:

```php
new InputArgument('clearers', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Cache clearer names');
new InputOption('all', null, InputOption::VALUE_NONE, 'Clear all registered caches');
new InputOption('list', null, InputOption::VALUE_NONE, 'List registered cache clearers');
```

Existing help descriptor arguments/options should be removed from `ClearCacheCommand`; they currently look copied from Symfony's help command and do not describe cache clearing.

## Failure Handling

Default behavior should fail fast:

- If one clearer throws, the command reports the failing clearer name and returns `Command::FAILURE`.
- Already-cleared caches are not rolled back.

A later `--continue-on-error` option could be added if needed, but it is not part of this first pass.

## Duplicate Names

`CacheClearerRegistry::add()` should throw an exception when a clearer with the same name already exists. Cache names are command API, so silent replacement would make behavior hard to reason about.

Suggested exception:

```php
ON\Cache\Exception\DuplicateCacheClearerException
```

## Initial Clearers

### Cache extension

Registers:

- `cache`: calls `CacheInterface::clear()`
- `app-cache-dir`: clears the application cache directory
- `config`: removes the cached application configuration file
- `lifecycle`: removes cached extension lifecycle ordering
- `container`: clears compiled container and proxy cache files

`app-cache-dir` should run last by default because it can remove cache files backing other clearers.

### Discovery extension

Registers:

- `discovery`: calls `DiscoveryExtension::clear()`

This replaces the hard-coded `if ($this->app->hasExtension('discovery'))` branch in `ClearCacheCommand`.

### Other built-in extensions

These should also register their owned caches:

- `router`: remove the configured FastRoute dispatch cache file
- `latte`: clear the configured Latte temp directory
- `file-routing`: clear compiled file-routing controllers, templates, and metadata
- `image`: remove generated image cache files
- `auto-wiring`: remove extension discovery cache
- `orm-schema`: remove Cycle schema cache

Large directory clearers, especially `image` and `app-cache-dir`, should use the fast rotate-delete path in `CachePathCleaner`: rename the target directory to a generated tombstone, recreate the original directory immediately, then delete the tombstone with a native remover and PHP fallback.

## Example Extension Integration

```php
use ON\Cache\CacheClearerDefinition;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Init\Init;
use Psr\Container\ContainerInterface;

public function register(Init $init): void
{
    $init->on(CacheClearersConfigureEvent::class, function (CacheClearersConfigureEvent $event): void {
        $event->registry->add(new CacheClearerDefinition(
            name: 'my-extension',
            label: 'My extension',
            clear: function (ContainerInterface $container): void {
                $container->get(MyCacheService::class)->clear();
            },
            priority: 50,
            description: 'Clears generated metadata for MyExtension.',
        ));
    });
}
```

## Tests

Add focused tests for:

- `CacheClearerRegistry` stores and returns definitions by name.
- Duplicate clearer names throw.
- `CacheExtension` emits `CacheClearersConfigureEvent` after `ContainerReadyEvent`.
- `CacheExtension` does not emit `CacheClearersConfigureEvent` for non-CLI application runs.
- An extension listening to `CacheClearersConfigureEvent` can register a clearer.
- `ClearCacheCommand --list` prints registered clearers.
- `ClearCacheCommand --all` invokes all `includedInAll` clearers in priority order.
- `ClearCacheCommand cache discovery` invokes only named clearers.
- A failing clearer returns `Command::FAILURE` and identifies the failed clearer.

## Migration Plan

1. Add `CacheClearerRegistry`, `CacheClearerDefinition`, and `CacheClearersConfigureEvent`.
2. Register `CacheClearerRegistry` in `CacheExtension`.
3. Move current `ClearCacheCommand` clearing logic into registered clearers.
4. Update `DiscoveryExtension` to register its clearer.
5. Update CLI docs for the new `cache:clear` options.
6. Add opt-in clearers for other built-in extensions in separate, smaller changes.

## Open Questions

- Should `app-cache-dir` be included in `--all` by default, or should it require explicit selection because it is broad?
- Should clearers be allowed to define dependencies, or is priority enough for now?
- Should the registry support cache warming later with a sibling `CacheWarmerDefinition`, or should warming be a separate extension concern?
