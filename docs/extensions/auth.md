# Authentication & Authorization

Overnight provides a complete authentication system with extensible authenticators.

## Configuration

```php
use ON\Auth\AuthExtension;

$app->install(new AuthExtension());
```

## Authentication Service

```php
$auth = $app->ext('auth');

// Check if user is logged in
if ($auth->hasIdentity()) {
    $user = $auth->getIdentity();
}

// Get authenticator
$authenticator = $auth->getAuthenticator();
```

## Creating an Authenticator

```php
use ON\Auth\AuthenticatorInterface;
use ON\Auth\Result;

class LoginAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher
    ) {}

    public function authenticate(array $credentials): Result
    {
        $email = $credentials['email'] ?? '';
        $password = $credentials['password'] ?? '';

        $user = $this->users->findByEmail($email);

        if (!$user) {
            return new Result(Result::FAILURE_IDENTITY_NOT_FOUND, null, [
                'User not found'
            ]);
        }

        if (!$this->hasher->verify($password, $user->password)) {
            return new Result(Result::FAILURE_CREDENTIAL_INVALID, null, [
                'Invalid password'
            ]);
        }

        return new Result(Result::SUCCESS, $user->id, [
            'Logged in successfully'
        ]);
    }
}
```

## Login Flow

```php
class AuthPage
{
    public function login(): Response
    {
        $data = $this->request->getParsedBody();

        $auth = $this->container->get(AuthenticationService::class);

        $result = $auth->authenticate(
            new LoginAuthenticator($this->users, $this->hasher),
            $data
        );

        if ($result->isValid()) {
            return new RedirectResponse('/dashboard');
        }

        return $this->render('auth/login', [
            'errors' => $result->getMessages(),
        ]);
    }

    public function logout(): Response
    {
        $auth = $this->container->get(AuthenticationService::class);
        $auth->logout();

        return new RedirectResponse('/');
    }
}
```

## Result Codes

```php
Result::FAILURE                       // General failure
Result::FAILURE_IDENTITY_NOT_FOUND    // User doesn't exist
Result::FAILURE_IDENTITY_AMBIGUOUS    // Multiple users found
Result::FAILURE_CREDENTIAL_INVALID    // Wrong password
Result::FAILURE_UNCATEGORIZED         // Other failure
Result::SUCCESS                       // Login successful
```

## User Interface

```php
use ON\Auth\User\UserInterface;

class User implements UserInterface
{
    public function __construct(
        public int $id,
        public string $email,
        public array $roles = []
    ) {}

    public function getId(): mixed
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}
```

### Built-in User Classes

```php
// StandardUser - Simple data container
use ON\Auth\User\StandardUser;

$user = new StandardUser(123, ['admin', 'editor']);

// AclUser - Role-based access control
use ON\Auth\User\AclUser;

$user = new AclUser(123, [
    'roles' => ['admin'],
    'permissions' => ['users.read', 'users.write'],
]);
```

## Storage

### Session Storage (default)

```php
use ON\Auth\Storage\SessionStorage;

$storage = new SessionStorage();
```

### Custom Storage

```php
use ON\Auth\StorageInterface;

class CookieStorage implements StorageInterface
{
    public function isEmpty(): bool
    {
        return !isset($_COOKIE['auth_token']);
    }

    public function read(): mixed
    {
        return $_COOKIE['auth_token'] ?? null;
    }

    public function write(mixed $contents): void
    {
        setcookie('auth_token', $contents, time() + 86400, '/');
    }

    public function clear(): void
    {
        setcookie('auth_token', '', time() - 3600, '/');
    }
}
```

## Security Middleware

Protect routes that require authentication:

```php
$pipeline = $app->ext('pipeline');

// Protect all routes under /admin
$pipeline->pipe(new SecurityMiddleware(), 50);
```

### SecurityMiddleware

```php
use ON\Auth\Middleware\SecurityMiddleware;

$middleware = new SecurityMiddleware([
    'login_route' => 'login',
    'allowed_routes' => ['login', 'register'],
]);
```

### Route-Level Security

Mark pages as secure by adding an `isSecure()` method (detected automatically by `SecurityMiddleware`):

```php
class AdminPage
{
    public function isSecure(): bool
    {
        return true;
    }
}
```

## Authorization

### Authorization Middleware

```php
use ON\Auth\Middleware\AuthorizationMiddleware;

$pipeline->pipe(new AuthorizationMiddleware(), 40);
```

### Permission Check

```php
class UserPage
{
    public function delete(int $id): Response
    {
        // Check if user has permission
        $auth = $this->container->get(AuthorizationService::class);

        if (!$auth->isAllowed('users.delete')) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        // Delete user
        $this->users->delete($id);

        return new JsonResponse(['success' => true]);
    }
}
```

### Role-Based Access

```php
// Check role
if ($user->hasRole('admin')) {
    // Admin action
}

// Check permission
if ($auth->isAllowed('posts.edit', $post)) {
    // Can edit
}
```

## Complete Example

### Login Page

```php
class LoginPage
{
    public function index(): Response
    {
        return $this->render('auth/login');
    }

    public function handleLogin(ServerRequestInterface $request): Response
    {
        $data = $request->getParsedBody();

        $auth = $this->container->get(AuthenticationService::class);

        $result = $auth->authenticate(
            new EmailPasswordAuthenticator(
                $this->container->get(UserRepository::class)
            ),
            [
                'email' => $data['email'],
                'password' => $data['password'],
            ]
        );

        if (!$result->isValid()) {
            return $this->render('auth/login', [
                'errors' => $result->getMessages(),
            ]);
        }

        return new RedirectResponse('/dashboard');
    }
}
```

### Protected Dashboard

```php
class DashboardPage
{
    public function isSecure(): bool
    {
        return true;
    }

    public function index(): string
    {
        return 'Success';
    }

    public function indexSuccess(): Response
    {
        return new HtmlResponse('<h1>Dashboard</h1>');
    }
}
```

## Auth Extension Configuration

```php
use ON\Auth\AuthExtension;

$extension = new AuthExtension([
    'storage' => SessionStorage::class,
    'session_namespace' => 'auth',
]);

$app->install($extension);
```
