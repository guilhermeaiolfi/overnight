# Benchmarking

Overnight now includes a small `phpbench` suite focused on framework bootstrap performance. The goal is to catch startup regressions early before we expand into request, mapper, REST, or GraphQL scenarios.

## Running Benchmarks

```bash
# Run the full benchmark suite
composer bench

# Run only bootstrap benchmarks
composer bench:bootstrap

# Record the current bootstrap baseline snapshot
composer bench:bootstrap:record

# Run bootstrap benchmarks and compare against the recorded baseline
composer bench:bootstrap:compare
```

You can also call `phpbench` directly:

```bash
vendor/bin/phpbench run --config=phpbench.json
```

## Current Scenarios

The initial suite benchmarks only bootstrap-related scenarios:

- bare application bootstrap in debug mode
- core extension bootstrap in debug mode
- core extension bootstrap in production mode with cold caches
- core extension bootstrap in production mode with warmed config/container caches
- production-style web stack bootstrap in debug mode
- production-style web stack bootstrap in production mode with cold caches
- production-style web stack bootstrap in production mode with warmed caches

The core extension scenario currently uses:

- `ON\Config\ConfigExtension`
- `ON\Container\ContainerExtension`
- `ON\Router\RouterExtension`
- `ON\View\ViewExtension`

The production-style scenario currently uses:

- `ON\Config\ConfigExtension`
- `ON\Container\ContainerExtension`
- `ON\Event\EventsExtension`
- `ON\Middleware\PipelineExtension`
- `ON\Router\RouterExtension`
- `ON\View\ViewExtension`
- `ON\Logging\LoggingExtension`
- `ON\Session\SessionExtension`
- `ON\Translation\TranslationExtension`
- `ON\Cache\CacheExtension`
- `ON\RateLimit\RateLimitExtension`
- `ON\Maintenance\MaintenanceExtension`

It also writes baseline and comparison artifacts under `benchmark/results/`.

## Interpreting Results

Use the numbers as a regression signal, not an absolute promise. Focus on:

- changes in the relative ordering between scenarios
- large shifts in mean or mode time
- memory growth alongside slower startup

Cold and warm production benchmarks answer different questions:

- cold bootstrap shows first-hit setup cost
- warm bootstrap shows steady-state startup after cache generation

## Baselines

The benchmark helper writes:

- `benchmark/results/bootstrap-baseline.xml` - committed machine-readable baseline
- `benchmark/results/bootstrap-baseline.txt` - committed human-readable summary
- `benchmark/results/bootstrap-baseline.json` - committed structured summary
- `benchmark/results/bootstrap-latest.*` - latest local run artifacts
- `benchmark/results/bootstrap-compare.*` - baseline versus latest comparison artifacts

## Next Steps

Once these bootstrap benchmarks feel stable, we can grow the suite into:

- route dispatch
- mapper conversion
- REST query normalization
- GraphQL registry generation
