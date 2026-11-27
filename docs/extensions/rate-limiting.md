# Rate Limiting

`RateLimitExtension` supplies `RateLimiterInterface` to REST, GraphQL, and custom middleware. When the cache is disabled or absent, the extension uses `InMemoryRateLimiter` and logs a warning if a logger is configured. That fallback keeps counters within a limiter instance.

## Cache backends

| Cache adapter | Counter scope |
| --- | --- |
| Symfony `FilesystemAdapter` | Workers sharing the same cache and lock directories. Attempts, reads, and resets for a counter use an exclusive filesystem lock. |
| Symfony `ArrayAdapter` | PHP process and adapter instance. |
| Other adapters, including Redis, Memcached, and cache chains | Supply a `RateLimiterInterface` implementation with atomic operations for that backend. An enabled unsupported cache causes the default limiter factory to throw. |

The filesystem adapter uses a bounded set of lock files in PHP's temporary directory by default. On POSIX the default directory is scoped to the effective user when the POSIX extension is available. Workers must resolve that default to the same directory on the same filesystem. If they use different users, temporary directories, or containers, set `lockDirectory` on `CacheConfig` to a shared absolute path. Use a filesystem that provides reliable exclusive `flock()` across every worker sharing the counters.

```php
$cacheConfig->set('lockDirectory', '/var/lib/my-app/rate-limit-locks');
```

Give all participating workers write access to the lock directory and its files. For workers running as different users, configure the shared group and file permissions accordingly. Keep the directory outside paths removed by cache clearing or redeploys. The lock files stay in place during normal operation so concurrent workers always lock the same files. A lock or counter write failure raises a runtime exception.

A cache-backed window begins with the first accepted attempt. Use a stable key, policy prefix, and window duration for each counter. Cache clearing can discard active counters.
