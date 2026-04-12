# Views & Templates

Overnight supports multiple template engines through a common interface.

## Supported Engines

- **Plates** (default) - League's native PHP templates
- **Latte** - Nette's powerful template engine

## Configuration

### Plates

```php
use ON\View\ViewConfig;

$config = new ViewConfig([
    'engine' => 'plates',
    'path' => 'templates',
    'layouts' => 'templates/layouts',
]);

$app->install($config);
```

### Latte

```php
use ON\View\ViewConfig;

$config = new ViewConfig([
    'engine' => 'latte',
    'path' => 'templates',
    'tempPath' => 'temp/latte',
]);

$app->install($config);
```

## Basic Usage

### In Pages (Controllers)

```php
class UserPage
{
    public function index(): Response
    {
        $users = User::all();
        
        return $this->render('users/index', [
            'users' => $users,
        ]);
    }
}
```

### With Layouts

```php
public function show(int $id): Response
{
    $user = User::find($id);
    
    return $this->render('users/show', [
        'user' => $user,
    ], 'layouts/main');
}
```

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

// Raw output
<?= $user->bio ?>

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

## View Helpers

### Built-in Functions

```php
// Escape
<?= $this->e($var) ?>          // Plates
{$var|noescape}                 // Latte

// Format
<?= $this->format($date) ?>
{$date|date:'Y-m-d'}

//Truncate
<?= $this->truncate($text, 100) ?>
{$text|truncate:100}

// Url
<a href="<?= $this->url()->current() ?>">Current URL</a>
<a n:href="UserPage:show 5">Link</a>
```

### Custom Helpers

#### Plates

```php
$engine = $plates->getEngine();

// Register function
$engine->registerFunction('markdown', function($text) {
    return Markdown::parse($text);
});

// Use in template
<?= $this->markdown($post->content) ?>
```

#### Latte

```php
$latte = $latteEngine->getLatte();

// Register filter
$latte->addFilter('markdown', function($text) {
    return Markdown::parse($text);
});

// Use in template
{$post->content|markdown}
```

## Error Pages

### Custom 404

```php
// In your NotFoundMiddleware or NotFoundHandler
return $this->render('errors/404', [
    'message' => 'Page not found',
], 'layouts/error');
```

### Error Layout

```php
<!-- templates/layouts/error.php -->
<!DOCTYPE html>
<html>
<head>
    <title>Error <?= $code ?></title>
</head>
<body>
    <h1>Error <?= $code ?></h1>
    <p><?= $this->e($message) ?></p>
    <a href="/">Go home</a>
</body>
</html>
```

## JSON Responses

For API endpoints:

```php
public function show(int $id): Response
{
    $user = User::find($id);
    
    if (!$user) {
        return new JsonResponse(['error' => 'Not found'], 404);
    }
    
    return new JsonResponse($user->toArray());
}
```

## View Data

### Sharing Data Across Views

```php
// In extension setup
$view->addData(['siteName' => 'My App']);

// Available in all templates
// <?= $siteName ?>
```

### Per-Request Data

```php
public function show(int $id): Response
{
    return $this->render('users/show', [
        'user' => $user,
        'pageTitle' => $user->name,
    ]);
}
```

## Best Practices

1. **Use layouts** - Keep templates DRY with layouts
2. **Escape output** - Always escape user data with `e()` or `|escape`
3. **Limit logic** - Keep templates focused on presentation
4. **Use sections** - Organize content with named sections
5. **Cache compiled** - Enable template caching in production
