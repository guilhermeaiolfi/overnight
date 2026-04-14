# File Routing

File-based routing maps URL paths directly to PHP files in a pages directory — no route definitions needed. Inspired by Next.js and Nuxt.

## Installation

```php
// config/extensions.php

use ON\FileRouting\FileRoutingExtension;

$app->install(FileRoutingExtension::class);
```

## How It Works

The extension maps URLs to files in the pages directory:

| URL | File |
|-----|------|
| `/about` | `src/Pages/about.php` or `src/Pages/about/index.php` |
| `/blog` | `src/Pages/blog.php` or `src/Pages/blog/index.php` |
| `/blog/hello-world` | `src/Pages/blog/hello-world.php` |

### HTTP Method Matching

Files can be scoped to specific HTTP methods by including the method in the filename:

| URL + Method | File |
|-------------|------|
| `GET /about` | `src/Pages/about.get.php` or `src/Pages/about.php` |
| `POST /contact` | `src/Pages/contact.post.php` or `src/Pages/contact.php` |

Method-specific files take priority over generic ones.

### Dynamic Segments (Slugs)

Use square brackets for dynamic URL segments:

| URL | File/Folder | Parameter |
|-----|-------------|-----------|
| `/blog/42` | `src/Pages/blog/[id].php` | `$id = "42"` |
| `/users/john/posts` | `src/Pages/users/[username]/posts.php` | `$username = "john"` |

Dynamic segments work for both files and folders.

### Resolution Order

For a URL like `/about`, the router checks in order:

1. `src/Pages/about.get.php` (method-specific file)
2. `src/Pages/about.php` (generic file)
3. `src/Pages/about/index.get.php` (method-specific index)
4. `src/Pages/about/index.php` (generic index)

First match wins.

## Page Files

Each page file can contain PHP logic and a template, separated by `?>`:

```php
<!-- src/Pages/about.php -->
<?php
// PHP logic runs first
$title = "About Us";
$team = ['Alice', 'Bob', 'Charlie'];
?>

<h1><?= $title ?></h1>
<ul>
    <?php foreach ($team as $member): ?>
        <li><?= $member ?></li>
    <?php endforeach ?>
</ul>
```

The PHP section is extracted and executed. The template section is rendered through the configured view engine.

### Returning Responses

Page files can return a response directly to skip template rendering:

```php
<?php
// src/Pages/api/health.get.php

use Laminas\Diactoros\Response\JsonResponse;

return new JsonResponse(['status' => 'ok']);
?>
```

### Accessing Route Parameters

Dynamic segment values are available as variables in the PHP section:

```php
<!-- src/Pages/blog/[slug].php -->
<?php
// $slug is available from the URL
$post = $repository->findBySlug($slug);

if (!$post) {
    return new HtmlResponse('Not found', 404);
}
?>

<h1><?= $page->e($post->title) ?></h1>
<div><?= $post->content ?></div>
```

### Setting the Layout

```php
<?php
$page->setLayout('layouts/admin');
?>

<h1>Admin Dashboard</h1>
```

## Directory Structure

```
src/Pages/
├── index.php                    # /
├── about.php                    # /about
├── contact.get.php              # GET /contact
├── contact.post.php             # POST /contact
├── blog/
│   ├── index.php                # /blog
│   ├── [slug].php               # /blog/:slug
│   └── [slug]/
│       └── comments.php         # /blog/:slug/comments
├── api/
│   ├── health.get.php           # GET /api/health
│   └── users/
│       ├── index.get.php        # GET /api/users
│       └── [id].get.php         # GET /api/users/:id
└── admin/
    └── index.php                # /admin
```

## API Endpoint

The extension registers an API endpoint for listing page files (useful for development tools and admin panels):

```
GET /__fileRouting?location=*
```

Returns a JSON list of all page files with metadata (path, filename, extension, size).

The URL is configurable via the `url` option.

## Configuration

### Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `pagesPath` | `string` | `src/Pages` | Directory containing page files |
| `cachePath` | `string` | `var/cache/filerouting/` | Directory for cached compiled pages |
| `controller` | `string` | `ON\FileRouting\Page\MainPage::index` | Controller that handles matched file routes |
| `url` | `string` | `__fileRouting` | URL path for the file listing API endpoint |

### Config File

```php
// config/filerouting.php

use ON\FileRouting\FileRoutingConfig;

return [
    FileRoutingConfig::class => [
        'pagesPath' => 'src/Pages',
        'cachePath' => 'var/cache/filerouting/',
        'controller' => 'ON\FileRouting\Page\MainPage::index',
        'url' => '__fileRouting',
    ],
];
```

## Caching

Page files are split into PHP logic and template parts. Both are cached separately:

- PHP logic: `var/cache/filerouting/about.php`
- Template: `var/cache/filerouting/about.phtml`

The cache is regenerated when the source file's modification time changes.

## Middleware Priority

The file routing middleware runs at priority **101**, just before the standard route middleware (priority 100). This means:

1. If a standard route matches the URL, it takes precedence
2. If no standard route matches, the file router checks for a matching page file
3. If no page file matches either, the request continues to the not-found handler

## Dependencies

| Extension | Required | Purpose |
|-----------|----------|---------|
| `config` | Yes | Reads `FileRoutingConfig` |
| `router` | Yes | Registers the API route, provides base path |
| `pipeline` | Yes | Pipes the file routing middleware |
| `view` | Yes | Renders templates via the configured engine |

## Events

This extension does not dispatch any events.

## See Also

- [Routing Extension](routing.md) — Standard route definitions
- [Views Extension](views.md) — Template engines
- [Middleware](../middleware.md) — Pipeline and middleware ordering
