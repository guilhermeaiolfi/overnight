# Sessions

Overnight provides session management with multiple backends.

## Configuration

```php
use ON\Session\SessionExtension;

$app->install(new SessionExtension([
    'driver' => 'native',
    'lifetime' => 120,
    'name' => 'overnight_session',
    'secure' => false,
    'httponly' => true,
]));
```

## Basic Usage

```php
$session = $app->ext('session');

// Set value
$session->set('user_id', 123);
$session->set('preferences', ['theme' => 'dark']);

// Get value
$userId = $session->get('user_id');
$preferences = $session->get('preferences', []);  // with default

// Check exists
if ($session->has('user_id')) {
    // ...
}

// Remove
$session->remove('user_id');

// Clear all
$session->destroy();
```

## Session Interface

```php
interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;
    public function all(): array;
    public function destroy(): void;
    public function getId(): string;
}
```

## Native Session

PHP's native session handling:

```php
use ON\Session\NativeSession;

$session = new NativeSession([
    'name' => 'myapp_session',
    'lifetime' => 3600,
    'path' => '/',
    'domain' => 'example.com',
    'secure' => true,
    'httponly' => true,
]);
```

## Session Manager

Resolve the appropriate session:

```php
$resolver = $app->ext('session');

$session = $resolver->resolve();
$session->set('key', 'value');
```

## Flash Messages

Store messages for one request:

```php
// Set flash message
$session->setFlash('success', 'User created successfully');
$session->setFlash('error', 'Something went wrong');

// Get and clear
$success = $session->getFlash('success');

// Get all and clear
$messages = $session->getFlashes();
```

## In Pages

```php
class UserPage
{
    public function login(ServerRequestInterface $request): Response
    {
        $session = $this->container->get(SessionInterface::class);
        
        // Validate credentials...
        
        $session->set('user_id', $user->id);
        $session->setFlash('success', 'Welcome back!');
        
        return new RedirectResponse('/dashboard');
    }

    public function dashboard(ServerRequestInterface $request): Response
    {
        $session = $this->container->get(SessionInterface::class);
        
        $userId = $session->get('user_id');
        
        if (!$userId) {
            return new RedirectResponse('/login');
        }
        
        $successMessage = $session->getFlash('success');
        
        return $this->render('dashboard', [
            'user_id' => $userId,
            'success' => $successMessage,
        ]);
    }
}
```

## Regenerate Session ID

Security best practice after login:

```php
public function login(ServerRequestInterface $request): Response
{
    // Validate credentials...
    
    $session = $this->container->get(SessionInterface::class);
    $session->regenerateId();  // Prevent session fixation
    $session->set('user_id', $user->id);
    
    return new RedirectResponse('/dashboard');
}
```

## CSRF Protection

```php
public function form(): Response
{
    $session = $this->container->get(SessionInterface::class);
    
    // Generate token
    $token = bin2hex(random_bytes(32));
    $session->set('_csrf_token', $token);
    
    return $this->render('form', ['csrf_token' => $token]);
}

public function submit(ServerRequestInterface $request): Response
{
    $session = $this->container->get(SessionInterface::class);
    
    $submittedToken = $request->getParsedBody()['_csrf_token'] ?? '';
    $sessionToken = $session->get('_csrf_token');
    
    if (!hash_equals($sessionToken, $submittedToken)) {
        return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    
    // Process form...
}
```

## Best Practices

1. **Regenerate on login** - Prevent session fixation
2. **Use secure cookies** - Set `secure` and `httponly`
3. **Set appropriate lifetime** - Balance security vs. convenience
4. **Clear sensitive data** - Remove data after use
5. **Use flash for redirects** - One-time messages
