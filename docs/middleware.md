# Middleware

Overnight uses PSR-15 compatible middleware with a priority-based pipeline.

## How Middleware Works

PSR-15 middleware follows the "onion" pattern:

```php
interface MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface;
}
```

Each middleware:
1. Receives the request
2. Optionally modifies it or performs logic
3. Passes it to `$handler->handle()`
4. Optionally processes the response
5. Returns the response

## Pipeline Configuration

```php
$pipeline = $app->ext('pipeline');

// Add middleware with priority (lower = runs first)
$pipeline->pipe(new CorsMiddleware(), 100);
$pipeline->pipe(new SessionMiddleware(), 90);
$pipeline->pipe(new AuthMiddleware(), 50);
$pipeline->pipe(new RouteMiddleware(), 0);
$pipeline->pipe(new ExecutionMiddleware(), -100);
```

## Built-in Middleware

### RouteMiddleware

Matches incoming requests to routes:

```php
$pipeline->pipe(RouteMiddleware::class, 0);
```

### ExecutionMiddleware

Executes the matched route's controller:

```php
$pipeline->pipe(ExecutionMiddleware::class, -100);
```

### ValidationMiddleware

Validates requests before execution:

```php
$pipeline->pipe(ValidationMiddleware::class, -50);
```

### NotFoundMiddleware

Handles 404 responses:

```php
$pipeline->pipe(NotFoundMiddleware::class, -200);
```

### OutputTypeMiddleware

Sets output type based on Accept header:

```php
$pipeline->pipe(OutputTypeMiddleware::class, 200);
```

### ErrorResponseGenerator

Generates error responses:

```php
// Standard error pages
$errorGenerator = new ErrorResponseGenerator($debug = false);

// Whoops-based development errors
$whoopsGenerator = new WhoopsErrorResponseGenerator();
```

### LazyLoadingMiddleware

Lazily loads middleware from container:

```php
$pipeline->pipe(LazyLoadingMiddleware::class, 50);

// Container must have this registered
$container->define('MyMiddleware', MyMiddleware::class);
```

## Custom Middleware

### Basic Middleware

```php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Pre-processing
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse([], 204)
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        }

        // Pass to next handler
        $response = $handler->handle($request);

        // Post-processing
        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

### Short-Circuit Middleware

Return early without calling the handler:

```php
class MaintenanceMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if ($this->isMaintenanceMode()) {
            return new HtmlResponse('Maintenance Mode', 503);
        }

        return $handler->handle($request);
    }
}
```

### Middleware with Container DI

Use lazy loading to inject dependencies:

```php
// Register in container
$container->define(ApiKeyMiddleware::class, function($container) {
    return new ApiKeyMiddleware(
        $container->get(ApiKeyRepository::class)
    );
});

// Use lazy loading
$pipeline->pipe(ApiKeyMiddleware::class, 80);
```

## Request Handler

The final request handler executes the matched route:

```php
class FinalHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class);
        
        if (!$routeResult->isSuccess()) {
            return new Response(404);
        }

        return $routeResult->process($request, $this);
    }
}
```

## MiddlewareFactory

Prepare middleware from various sources:

```php
$factory = $container->get(MiddlewareFactory::class);

// From service name
$m1 = $factory->prepare('MyMiddleware');

// From MiddlewareInterface instance
$m2 = $factory->prepare(new MyMiddleware());

// From callable
$m3 = $factory->prepare(function ($request, $handler) {
    return $handler->handle($request);
});

// Create pipeline
$pipeline = $factory->pipeline($m1, $m2, $m3);
```

## Middleware Priority

Middleware executes in order of priority (lowest first):

```
Priority 100: First middleware (closest to request)
    ↓
Priority 50: Second middleware
    ↓
Priority 0: Route matching (last "pre-execution" middleware)
    ↓
    [Page/Controller executes]
    ↑
Priority 0: Response processing
    ↑
Priority 50: Post-processing
    ↑
Priority 100: Last middleware (closest to response)
```

## Best Practices

1. **Keep middleware focused** - Each middleware should do one thing
2. **Don't buffer responses** - Pass through unless you need to modify
3. **Handle errors** - Wrap `$handler->handle()` in try-catch when needed
4. **Order matters** - Put CORS/auth early, error handling late
