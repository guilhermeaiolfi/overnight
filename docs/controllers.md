# Controllers (Pages)

In Overnight, controllers are called **Pages**. They handle HTTP requests and return responses. Pages are plain PHP classes — no base class required. Dependencies are resolved automatically via the container.

## Creating a Page

### Basic Page

```php
<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Laminas\Diactoros\Response\JsonResponse;

class UserPage
{
    public function index(): Response
    {
        $users = User::all();
        return new JsonResponse(['users' => $users]);
    }

    public function show(int $id): Response
    {
        $user = User::find($id);
        
        if (!$user) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }
        
        return new JsonResponse(['user' => $user]);
    }
}
```

## Method Naming

### HTTP Method Mapping

Match method names to HTTP verbs:

```php
class UserPage
{
    public function index(): Response    // GET /users
    public function show(int $id): Response    // GET /users/{id}
    public function create(): Response    // GET /users/create
    public function store(): Response    // POST /users
    public function edit(int $id): Response    // GET /users/{id}/edit
    public function update(int $id): Response    // PUT/PATCH /users/{id}
    public function destroy(int $id): Response    // DELETE /users/{id}
}
```

### Custom Methods

```php
class UserPage
{
    public function activate(int $id): Response    // POST /users/{id}/activate
    public function deactivate(int $id): Response    // POST /users/{id}/deactivate
    public function search(): Response    // GET /users/search
}
```

## Route Parameter Injection

Parameters from the URL are automatically injected:

```php
// Route: /users/{id}
// URL: /users/42

public function show(int $id): Response
{
    // $id = 42 (integer)
    $user = User::find($id);
}
```

### Multiple Parameters

```php
// Route: /users/{userId}/posts/{postId}
// URL: /users/1/posts/5

public function showPost(int $userId, int $postId): Response
{
    // $userId = 1
    // $postId = 5
}
```

### Type Casting

| Type | URL | Injected As |
|------|-----|-------------|
| `int` | `"42"` | `42` |
| `float` | `"3.14"` | `3.14` |
| `bool` | `"1"` | `true` |
| `string` | `"test"` | `"test"` |
| (none) | any | string |

### Examples

```php
public function show(int $id, string $format): Response
{
    // URL: /users/42/json → $id = 42, $format = "json"
}

public function archive(int $year, int $month = 1): Response
{
    // URL: /blog/2024 → $year = 2024, $month = 1 (default)
}

public function search(string $q, int $page = 1, int $limit = 20): Response
{
    // URL: /search?q=test&page=2 → $q = "test", $page = 2, $limit = 20
}
```

## Accessing the Request

### Auto-Injection

```php
public function index(ServerRequestInterface $request): Response
{
    // $request is automatically injected
}
```

### Combined with Route Parameters

```php
public function show(int $id, ServerRequestInterface $request): Response
{
    // Route param
    $user = User::find($id);
    
    // From request
    $headers = $request->getHeaders();
    $query = $request->getQueryParams();
    $body = $request->getParsedBody();
    
    return new JsonResponse(['user' => $user]);
}
```

## Returning Responses

### JSON Response

```php
public function show(int $id): Response
{
    $user = User::find($id);
    return new JsonResponse(['user' => $user]);
}

// With status code
return new JsonResponse(['error' => 'Not found'], 404);

// With headers
return new JsonResponse($data, 200, [
    'X-Total-Count' => 100,
]);
```

### HTML Response

```php
public function show(int $id): Response
{
    $user = User::find($id);
    return new HtmlResponse($this->render('users/show', [
        'user' => $user,
    ]));
}
```

### Empty Response

```php
public function destroy(int $id): Response
{
    User::delete($id);
    return new EmptyResponse(204);
}
```

### Redirect Response

```php
use Laminas\Diactoros\Response\RedirectResponse;

public function store(ServerRequestInterface $request): Response
{
    // Create user...
    return new RedirectResponse('/users/' . $user->id);
}
```

### Response with View

```php
use ON\View\ViewManager;
use Psr\Http\Message\ResponseInterface;

class UserPage
{
    public function __construct(
        private ViewManager $view
    ) {
    }

    public function show(int $id): Response
    {
        $user = User::find($id);
        
        $html = $this->view->render('users/show', [
            'user' => $user,
        ]);
        
        return new HtmlResponse($html);
    }
}
```

## Container Dependency Injection

```php
class UserPage
{
    public function __construct(
        private UserRepository $users
    ) {
    }

    public function show(int $id): Response
    {
        $user = $this->users->find($id);
        return new JsonResponse(['user' => $user]);
    }
}
```

### Via Request

```php
public function store(ServerRequestInterface $request): Response
{
    $data = $request->getParsedBody();
    $user = User::create($data);
    
    return new JsonResponse($user, 201);
}
```

## Error Handling

```php
public function show(int $id): Response
{
    try {
        $user = User::findOrFail($id);
        return new JsonResponse(['user' => $user]);
    } catch (ModelNotFoundException $e) {
        return new JsonResponse(['error' => 'User not found'], 404);
    } catch (\Exception $e) {
        return new JsonResponse(['error' => 'Server error'], 500);
    }
}
```

## Forwarding Requests

```php
public function show(int $id): Response
{
    $user = User::find($id);
    
    if (!$user->isActive) {
        // Forward to error page
        return $this->processForward('ErrorPage::inactive');
    }
    
    return new JsonResponse(['user' => $user]);
}
```

## Best Practices

1. **Keep pages thin** - Move business logic to services
2. **Return proper status codes** - 200, 201, 400, 404, 403, 500
3. **Validate input** - Always validate user data
4. **Use type hints** - Enables automatic parameter casting
5. **Return responses early** - Exit fast on errors
6. **Use dependency injection** - Don't use global state
