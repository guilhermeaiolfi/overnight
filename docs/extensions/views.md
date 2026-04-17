# Views & Templates

Overnight supports multiple template engines through a common interface.

## Supported Engines

- **Plates** (built-in) - League's native PHP templates, included in `ViewExtension`
- **Latte** (separate extension) - Nette's powerful template engine, provided by `LatteExtension`

## Configuration

### Plates (Built-in)

Plates is included in the core `ViewExtension`. No additional extension needed:

```php
use ON\View\ViewExtension;

$app->install(ViewExtension::class);
```

### Latte (Separate Extension)

Latte is a separate extension that requires the `view` and `container` extensions:

```php
use ON\View\ViewExtension;
use ON\View\Latte\LatteExtension;

// Install the base view extension first
$app->install(ViewExtension::class);

// Then install Latte
$app->install(LatteExtension::class);
```

`LatteExtension` (`ON\View\Latte\LatteExtension`) registers the `LatteRenderer` factory in the container.

## Architecture

The view system is decoupled from pages. Pages are plain classes — they don't need to extend any base class or implement any interface. Rendering is handled by `ViewInterface`, which pages request via constructor injection.

### Key Classes

- **`ViewInterface`** — interface for rendering templates. Methods: `render(array $data, ?string $templateName, ?string $layoutName)`, `setDefaultTemplateName()`, `getDefaultTemplateName()`.
- **`View`** — concrete implementation. Resolves layouts, templates, and renderers from `ViewConfig`.
- **`ViewResult`** — value object returned by action methods. Carries the view method name and data.
- **`ViewBuilderTrait`** — used by `ActionMiddlewareDecorator` to dispatch action return values to view methods.

### Data Flow

```
Request → Action Method → returns ViewResult → ViewBuilder → View Method → ViewInterface::render()
```

1. The action method runs business logic and returns a `ViewResult` (or a `Response` directly).
2. `ViewBuilderTrait` reads the `ViewResult` and calls the corresponding view method on the page.
3. The view method uses `ViewInterface::render()` to produce the HTML response.

## Basic Usage

### Page with View (HTML)

Pages that need template rendering declare `ViewInterface` in their constructor:

```php
use Laminas\Diactoros\Response\HtmlResponse;
use ON\View\ViewInterface;
use ON\View\ViewResult;

class UserPage
{
    public function __construct(
        public ViewInterface $view
    ) {}

    // Action method — returns ViewResult with data
    public function index(): ViewResult
    {
        $users = User::all();

        return new ViewResult('success', ['users' => $users]);
    }

    // View method — receives data, renders template
    public function successView(ViewResult $result): HtmlResponse
    {
        return new HtmlResponse(
            $this->view->render($result->data, 'users/index')
        );
    }
}
```

### Page without View (API)

Pages that don't need rendering simply don't ask for `ViewInterface`:

```php
use Laminas\Diactoros\Response\JsonResponse;

class UserApiPage
{
    public function index(): JsonResponse
    {
        $users = User::all();

        return new JsonResponse(['data' => $users]);
    }
}
```

### Multiple View Outcomes

Action methods can return different `ViewResult` names to handle different outcomes:

```php
class ContactPage
{
    public function __construct(
        public ViewInterface $view
    ) {}

    public function create(ServerRequestInterface $request): ViewResult
    {
        $data = $request->getParsedBody();

        if ($this->sendEmail($data)) {
            return new ViewResult('success', ['message' => 'Message sent!']);
        }

        return new ViewResult('error', ['error' => 'Failed to send', 'form' => $data]);
    }

    public function successView(ViewResult $result): HtmlResponse
    {
        return new HtmlResponse($this->view->render($result->data, 'contact/success'));
    }

    public function errorView(ViewResult $result): HtmlResponse
    {
        return new HtmlResponse($this->view->render($result->data, 'contact/form'));
    }
}
```

### Returning a String (Shorthand)

Returning a plain string from an action is a shorthand for `new ViewResult('string', [])`:

```php
public function index(): string
{
    return 'Success'; // calls successView() with empty data
}
```

### Returning a Response Directly

If an action returns a `ResponseInterface`, it's returned as-is — no view method is called:

```php
public function download(): ResponseInterface
{
    return new StreamResponse($fileStream, 200, ['Content-Type' => 'application/pdf']);
}
```

## ViewResult

`ViewResult` is an immutable value object that carries data from the action to the view method.

```php
$result = new ViewResult('success', ['user' => $user, 'message' => 'Updated']);

// Access data
$result->get('user');              // $user
$result->get('missing', 'default'); // 'default'
$result->has('user');              // true
$result->toArray();                // ['user' => $user, 'message' => 'Updated']

// ArrayAccess (read-only)
$result['user'];                   // $user
isset($result['user']);            // true
```

## View Methods

View methods are called by the framework after the action method returns a `ViewResult`. The framework resolves the view method name using these conventions (in order):

1. `{viewName}View` — e.g., `successView`
2. `{action}{ViewName}View` — e.g., `createSuccessView`
3. `{viewName}` — e.g., `success`

### Parameter Injection via Executor

When the `Executor` is available (default), view methods can declare whatever parameters they need. The framework injects them automatically:

```php
// Receive the full ViewResult
public function successView(ViewResult $result): HtmlResponse { ... }

// Receive individual data keys by name
public function successView(array $user, string $message): HtmlResponse { ... }

// Receive the request
public function successView(ViewResult $result, ServerRequestInterface $request): HtmlResponse { ... }

// Mix and match
public function successView(string $message, ViewResult $result): HtmlResponse { ... }
```

Available parameters for injection:
- `ViewResult $result` — the full ViewResult object
- `ServerRequestInterface $request` — the current request
- `RequestHandlerInterface $delegate` — the request handler
- `array $data` — the raw data array
- Any key from `$result->data` by name (e.g., `$user`, `$message`)

### Cross-Page View References

You can reference a view method on a different page using the `Page:method` syntax:

```php
public function create(): ViewResult
{
    // Calls successView() on SharedPage
    return new ViewResult('SharedPage:success', ['message' => 'Done']);
}
```

## Validation

Validation is handled by `ValidationMiddleware`, separate from the view system. Pages define validation methods that the middleware calls before the action:

```php
class PostPage
{
    public function __construct(public ViewInterface $view) {}

    // Called before create() — return true to proceed, false to stop
    public function createValidate(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();
        return !empty($body['title']);
    }

    // Called when validation fails
    public function handleError(ServerRequestInterface $request): ViewResult
    {
        return new ViewResult('error', ['errors' => ['Title is required']]);
    }

    public function create(ServerRequestInterface $request): ViewResult
    {
        // Only reached if createValidate() returned true
        $post = Post::create($request->getParsedBody());
        return new ViewResult('success', ['post' => $post]);
    }

    public function successView(ViewResult $result): HtmlResponse
    {
        return new HtmlResponse($this->view->render($result->data, 'post/show'));
    }

    public function errorView(ViewResult $result): HtmlResponse
    {
        return new HtmlResponse($this->view->render($result->data, 'post/form'));
    }
}
```

Validation method resolution order:
1. `{action}Validate` — e.g., `createValidate`
2. `validate` — generic for all actions
3. `defaultValidate` — fallback (defined in `AbstractPage`)

Error handler resolution order:
1. `handleError`
2. `defaultHandleError` — fallback (defined in `AbstractPage`)

## Forwarding Requests

If you need to forward a request to another controller/page from within a page, inject `Application` and use its `processForward` method:

```php
use ON\Application;
use ON\View\ViewInterface;

class AdminPage
{
    public function __construct(
        protected Application $app,
        public ViewInterface $view
    ) {}

    public function dashboard(ServerRequestInterface $request): mixed
    {
        if (!$this->hasPermission()) {
            return $this->app->processForward('App\Page\LoginPage::index', $request);
        }

        return new ViewResult('success', ['stats' => $this->getStats()]);
    }
}
```

## With Layouts

```php
public function successView(ViewResult $result): HtmlResponse
{
    // render(data, templateName, layoutName)
    return new HtmlResponse(
        $this->view->render($result->data, 'users/show', 'admin')
    );
}
```

When `layoutName` is omitted, the default layout from `ViewConfig` is used.

## Template Directory Structure

```
templates/
├── layouts/
│   ├── main.php
│   └── admin.php
├── users/
│   ├── index.php
│   └── show.php
└── errors/
    └── 404.php
```

## Plates Templates

### Basic Template

```php
<!-- templates/users/show.php -->

<h1><?= $this->e($user->name) ?></h1>

<p>Email: <?= $user->email ?></p>

<a href="/users">Back to list</a>
```

### Using Layouts

```php
<!-- templates/layouts/main.php -->
<!DOCTYPE html>
<html>
<head>
    <title><?= $this->section('title') ?></title>
    <?= $this->section('head') ?>
</head>
<body>
    <header>
        <nav><?= $this->section('nav') ?></nav>
    </header>

    <main>
        <?= $this->section('content') ?>
    </main>

    <footer>
        <?= $this->section('footer') ?>
    </footer>
</body>
</html>
```

### Sections

```php
<!-- templates/users/show.php -->

<?php $this->layout('layouts/main', ['title' => 'User Profile']) ?>

<?php $this->start('content') ?>
<h1><?= $this->e($user->name) ?></h1>
<?php $this->stop() ?>
```

### Helpers

```php
// Escape output
<?= $this->e($user->name) ?>

// Conditionals
<?php if ($user->isActive): ?>
    <span class="badge">Active</span>
<?php endif ?>

// Loops
<?php foreach ($users as $user): ?>
    <li><?= $this->e($user->name) ?></li>
<?php endforeach ?>
```

## Latte Templates

### Basic Template

```latte
<!-- templates/users/show.latte -->

<h1>{$user->name}</h1>

<p>Email: {$user->email}</p>

<a n:href="UserPage:index">Back to list</a>
```

### Layouts

```latte
<!-- templates/layouts/main.latte -->
<!DOCTYPE html>
<html>
<head>
    <title>{include title}</title>
    {include head}
</head>
<body>
    {include content}
</body>
</html>
```

### Blocks

```latte
<!-- templates/users/show.latte -->

{layout 'layouts/main.latte'}

{block title}User Profile{/block}

{block content}
<h1>{$user->name}</h1>
{/block}
```

### Nette-style Attributes

```latte
<a n:href="UserPage:show $user->id">View</a>

<ul n:foreach="$users as $user">
    <li>{$user->name}</li>
</ul>

<span class="badge" n:if="$user->isActive">Active</span>
```

## Custom Helpers

### Plates

```php
$engine = $plates->getEngine();

$engine->registerFunction('markdown', function($text) {
    return Markdown::parse($text);
});

// Use in template
<?= $this->markdown($post->content) ?>
```

### Latte

```php
$latte = $latteEngine->getLatte();

$latte->addFilter('markdown', function($text) {
    return Markdown::parse($text);
});

// Use in template
{$post->content|markdown}
```

## Error Pages

### Custom 404

```php
class NotFoundPage
{
    public function __construct(public ViewInterface $view) {}

    public function index(): ViewResult
    {
        return new ViewResult('success', ['message' => 'Page not found']);
    }

    public function successView(ViewResult $result): HtmlResponse
    {
        return new HtmlResponse(
            $this->view->render($result->data, 'errors/404', 'error'),
            404
        );
    }
}
```

## Sharing Data Across Views

```php
// In extension setup
$view->addData(['siteName' => 'My App']);

// Available in all templates
// <?= $siteName ?>
```

## Best Practices

1. **Use `ViewResult`** — return data explicitly from actions, don't use shared mutable state
2. **Keep pages plain** — no required base class. Inject `ViewInterface` only when you need rendering
3. **Use layouts** — keep templates DRY with layouts
4. **Escape output** — always escape user data with `e()` or `|escape`
5. **Limit logic** — keep templates focused on presentation
6. **Use sections** — organize content with named sections
7. **Cache compiled** — enable template caching in production
