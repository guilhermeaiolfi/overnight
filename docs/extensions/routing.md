# Routing

Overnight uses FastRoute for powerful routing with route groups, constraints, and file-based routing.

## Basic Routes

### Adding Routes

```php
$router = $app->ext('router');

// Basic route
$router->addRoute('/users', 'UserPage::index', ['GET']);

// With route name
$router->addRoute('/users/{id}', 'UserPage::show', ['GET'], 'user.show');
```

### HTTP Methods

```php
// GET
$router->get('/users', 'UserPage::index');

// POST
$router->post('/users', 'UserPage::create');

// PUT
$router->put('/users/{id}', 'UserPage::update');

// PATCH
$router->patch('/users/{id}', 'UserPage::partialUpdate');

// DELETE
$router->delete('/users/{id}', 'UserPage::delete');

// Any method
$router->any('/users', 'UserPage::handler');
```

### Route Parameters

Parameters are enclosed in curly braces:

```php
$router->get('/users/{id}', 'UserPage::show');
$router->get('/posts/{postId}/comments/{commentId}', 'CommentPage::show');
```

### Parameter Constraints

Constrain parameters with regex:

```php
// Only allow numeric IDs
$router->get('/users/{id}', 'UserPage::show', ['GET'], null, [
    'constraints' => ['id' => '[0-9]+']
]);

// Multiple constraints
$router->get('/posts/{year}/{month}', 'ArchivePage::show', ['GET'], null, [
    'constraints' => [
        'year' => '[0-9]{4}',
        'month' => '[0-9]{2}',
    ]
]);
```

### Default Values

Provide defaults for optional segments:

```php
$router->get('/blog/{year?}/{month?}', 'BlogPage::archive', ['GET'], null, [
    'defaults' => [
        'year' => date('Y'),
        'month' => null,
    ]
]);
```

## Route Groups

Group routes with shared prefix:

```php
// Admin routes
$router->addGroup('/admin', function($router) {
    $router->get('/users', 'Admin\UserPage::index');
    $router->get('/users/{id}', 'Admin\UserPage::show');
    $router->post('/users', 'Admin\UserPage::create');
});

// API routes
$router->addGroup('/api/v1', function($router) {
    $router->get('/users', 'Api\V1\UserPage::index');
    $router->post('/users', 'Api\V1\UserPage::create');
});
```

### Nested Groups

```php
$router->addGroup('/api', function($api) {
    $api->addGroup('/v1', function($v1) {
        $v1->get('/users', 'Api\V1\UserPage::index');
    });
    
    $api->addGroup('/v2', function($v2) {
        $v2->get('/users', 'Api\V2\UserPage::index');
    });
});
```

## Parameter Injection

Route parameters are automatically injected into controller methods.

### Type Casting

| Type Hint | URL Value | Injected As |
|-----------|-----------|-------------|
| `int` | `"42"` | `42` |
| `float` | `"3.14"` | `3.14` |
| `bool` | `"1"` or `"true"` | `true` |
| `string` | `"hello"` | `"hello"` |
| (none) | any | string |

### Examples

```php
// Single parameter
public function show(int $id): Response
{
    // URL: /users/42 → $id = 42
}

// Multiple parameters
public function showComment(int $postId, int $commentId): Response
{
    // URL: /posts/5/comments/10 → $postId = 5, $commentId = 10
}

// With request
public function show(string $slug, ServerRequestInterface $request): Response
{
    // $slug from URL, $request is auto-injected
}
```

## URL Generation

### Named Routes

```php
// Generate URL by name
$url = $router->gen('user.show', ['id' => 42]);
// → /users/42

// With query string
$url = $router->gen('user.show', ['id' => 42], ['foo' => 'bar']);
// → /users/42?foo=bar

// With fragment
$url = $router->gen('user.show', ['id' => 42], [], 'section');
// → /users/42#section
```

### URL Generation Methods

```php
// Basic generation
$router->generateUri('user.show', ['id' => 42]);

// Extended generation (with query, fragment)
$router->gen('user.show', [
    'id' => 42,
    'query' => ['page' => 2],
    'fragment' => 'comments',
]);

// Get base URL
$baseUrl = $router->getBasePath();
```

## Route Attributes (PHP 8)

Define routes with attributes:

```php
use ON\Router\Attribute\Route;

class UserPage
{
    #[Route('/users', 'user.index', methods: ['GET'])]
    public function index(): Response
    {
        // ...
    }

    #[Route('/users/{id}', 'user.show', methods: ['GET'], requirements: ['id' => '[0-9]+'])]
    public function show(int $id): Response
    {
        // ...
    }

    #[Route('/users', 'user.create', methods: ['POST'])]
    public function create(): Response
    {
        // ...
    }
}
```

### Processing Attributes

```php
$processor = new RouteAttributeProcessor($container);
$processor->discover([UserPage::class, PostPage::class]);
```

## File-Based Routing

Alternative routing based on file structure:

### Directory Structure

```
pages/
├── index.php           # Matches /
├── users/
│   ├── index.php       # Matches /users
│   ├── [id].php        # Matches /users/{id}
│   └── [userId]/
│       └── posts/
│           └── [postId].php  # Matches /users/{userId}/posts/{postId}
└── about.php           # Matches /about
```

### File Naming Conventions

| File | URL | Method |
|------|-----|--------|
| `index.php` | `/` or `/users` | Any |
| `index.get.php` | `/users` | GET only |
| `users.php` | `/users` | Any |
| `users.get.php` | `/users` | GET only |
| `[id].php` | `/users/123` | Any |
| `[id].get.php` | `/users/123` | GET only |

### Page Files

```php
<?php
// pages/users/[id].php

public function get(int $id): Response
{
    $user = User::find($id);
    return new JsonResponse($user);
}

public function put(int $id, ServerRequestInterface $request): Response
{
    $data = $request->getParsedBody();
    $user = User::update($id, $data);
    return new JsonResponse($user);
}

public function delete(int $id): Response
{
    User::delete($id);
    return new EmptyResponse(204);
}
```

## Route Result

The `RouteResult` contains matched route information:

```php
$result = $request->getAttribute(RouteResult::class);

if ($result->isSuccess()) {
    $route = $result->getMatchedRoute();
    $params = $result->getMatchedParams();
    $name = $result->getMatchedRouteName();
} else {
    $allowed = $result->getAllowedMethods();
}
```

## Route Options

Additional route configuration:

```php
$router->addRoute('/api/users', 'Api\UserPage::index', ['GET'], null, [
    'defaults' => ['format' => 'json'],
    'requirements' => ['format' => 'json|xml'],
    'host' => 'api.example.com',
    'scheme' => 'https',
]);
```

## Caching Routes

Enable route caching for performance:

```php
$config = new RouterConfig([
    'cache_enabled' => true,
    'cache_file' => 'data/routes.cache',
]);
```

## Best Practices

1. **Use route names** - Makes URL generation easier
2. **Group related routes** - Keep code organized
3. **Use constraints** - Validate parameters early
4. **Keep URLs RESTful** - Follow REST conventions
5. **Cache routes in production** - Improves performance
