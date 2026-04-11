# ActionMiddlewareDecorator

The `ActionMiddlewareDecorator` is responsible for executing controller/page methods with automatically resolved dependencies.

## Location

```
src/Router/ActionMiddlewareDecorator.php
```

## Purpose

1. Resolves route parameters from the request
2. Injects them as method arguments based on parameter names
3. Type-casts parameters based on type hints
4. Executes the controller method via the `Executor`
5. Builds the view/response

## How It Works

### 1. Parameter Resolution

The `resolveRouteParams()` method:

```php
protected function resolveRouteParams(ServerRequestInterface $request): array
{
    $args = [];
    $result = $request->getAttribute(RouteResult::class);

    if (! $result || ! $result->isSuccess()) {
        return $args;
    }

    $routeParams = $result->getMatchedParams();
    $method = new ReflectionMethod($this->instance, $this->method);

    foreach ($method->getParameters() as $param) {
        $paramName = $param->getName();

        if (isset($routeParams[$paramName])) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                $value = $routeParams[$paramName];
                settype($value, $type->getName());
                $args[$paramName] = $value;
            } elseif (! $type) {
                $args[$paramName] = $routeParams[$paramName];
            }
        }
    }

    return $args;
}
```

### 2. Method Execution

The `execute()` method:

```php
public function execute(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    $args = $this->resolveRouteParams($request);
    $args[ServerRequestInterface::class] = $request;
    $args[RequestHandlerInterface::class] = $handler;

    $action_response = $this->executor->execute([$this->instance, $this->method], $args);

    return $this->buildView($this->instance, $this->method, $action_response, $request, $handler);
}
```

### 3. Dependency Resolution Order

1. **Route parameters** (by name) - from `resolveRouteParams()`
2. **PSR types** (by class name) - resolved by `TypeHintContainerResolver`
3. **Container services** - resolved by `TypeHintContainerResolver`
4. **Default values** - resolved by `DefaultValueResolver`

## Key Classes

| Class | Role |
|-------|------|
| `RouteResult` | Contains matched route and parameters |
| `Executor` | PHP-DI Invoker wrapper for method execution |
| `TypeHintContainerResolver` | Resolves class dependencies from container |
| `ResolverChain` | Orchestrates multiple parameter resolvers |

## Extending

### Adding Custom Parameter Resolvers

To add support for custom parameter types:

1. Implement a resolver class
2. Add it to the `ResolverChain` in `ExecutorFactory`

```php
// Example: Custom resolver for request headers
class HeaderResolver implements ParameterResolver
{
    public function getParameters(
        ReflectionFunctionAbstract $reflection,
        array $providedParameters,
        array $resolvedParameters
    ): array {
        foreach ($reflection->getParameters() as $index => $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === Header::class) {
                $resolvedParameters[$index] = new Header($param->getName());
            }
        }
        return $resolvedParameters;
    }
}
```

### Middleware Pipeline

The decorator supports multiple middleware methods:

- `execute()` - Main method execution
- `validate()` - Request validation
- `loggedCheck()` - Authentication check
- `checkPermissions()` - Authorization check

These are chained in the middleware pipeline and can be extended for custom behavior.
