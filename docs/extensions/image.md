# Image Extension

The Image extension provides on-the-fly image manipulation and caching for the Overnight framework. It uses [Intervention Image](https://image.intervention.io/) under the hood and serves transformed images via encrypted URLs — no one can guess or tamper with the image parameters.

## Table of Contents

1. [Installation](#installation)
2. [How It Works](#how-it-works)
3. [Configuration](#configuration)
4. [Generating Image URLs](#generating-image-urls)
5. [Templates](#templates)
6. [Custom Template (Dynamic Transformations)](#custom-template-dynamic-transformations)
7. [Caching](#caching)
8. [Encrypters](#encrypters)
9. [404 Fallback Image](#404-fallback-image)

---

## Installation

The extension requires `intervention/image` v3:

```bash
composer require intervention/image
```

Then install the extension:

```php
use ON\Image\ImageExtension;

ImageExtension::install($app);
```

The extension requires the `container` and `config` extensions.

---

## How It Works

1. You call `$imageManager->getUri($path, $template)` to generate an encrypted URL
2. The URL looks like `/i/a3f2...c8d1.jpg` — the path and template are encrypted in the token
3. When a browser requests that URL, the `ImageManager` middleware intercepts it
4. It decrypts the token, finds the original image, applies the template transformation
5. The result is cached to disk so subsequent requests serve the static file directly (via the web server)
6. The response includes proper `Cache-Control` and `ETag` headers

The encryption prevents URL tampering — users can't change dimensions or request arbitrary files by modifying the URL.

---

## Configuration

Configuration is managed via `ImageConfig`:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `basePath` | string | `i/` | URL base path for image routes |
| `404ImagePath` | string | `404i.png` | Fallback image when source file is not found |
| `templates` | array | `['custom' => CustomTemplate::class]` | Named template classes for transformations |
| `driver` | string | `Intervention\Image\Drivers\Gd\Driver` | Intervention Image driver (`Gd` or `Imagick`) |
| `paths` | array | `[]` | Directories to search for source images |
| `cache.lifetime` | int | (minutes) | Browser cache lifetime in minutes |
| `cache.class` | string | `FileSystem::class` | Cache implementation class |
| `encrypter.class` | string | `OpenSSL::class` | Encrypter implementation class |
| `key` | string | `$_ENV['APP_SALT']` | Encryption key for URL tokens |

```php
// config/image.php
use ON\Image\ImageConfig;
use ON\Image\Encrypter\OpenSSL;
use ON\Image\Modifier\CoverModifier;

return [
    ImageConfig::class => [
        'basePath' => 'i/',
        'driver' => \Intervention\Image\Drivers\Gd\Driver::class,
        'paths' => [
            'storage/uploads',
            'public/images',
        ],
        'templates' => [
            'custom' => \ON\Image\CustomTemplate::class,
            'thumb' => function ($image) {
                return $image->cover(width: 150, height: 150);
            },
            'medium' => function ($image) {
                return $image->scale(width: 800);
            },
            'large' => function ($image) {
                return $image->scale(width: 1200);
            },
        ],
        'cache' => [
            'lifetime' => 43200, // 30 days in minutes
            'class' => \ON\Image\Cache\FileSystem::class,
        ],
        'encrypter' => [
            'class' => OpenSSL::class,
        ],
    ],
];
```

### Using Imagick Instead of GD

```php
'driver' => \Intervention\Image\Drivers\Imagick\Driver::class,
```

Requires the `imagick` PHP extension.

---

## Generating Image URLs

Use the `ImageManager` to generate encrypted URLs in your templates or controllers:

```php
// Get ImageManager from the container
$imageManager = $container->get(\ON\Image\ImageManager::class);

// Generate a thumbnail URL
$thumbUrl = $imageManager->getUri('uploads/photo.jpg', 'thumb');
// → "/i/a3f2...c8d1.jpg"

// Generate a medium-sized URL
$mediumUrl = $imageManager->getUri('uploads/photo.jpg', 'medium');

// Original image (no transformation)
$originalUrl = $imageManager->getUri('uploads/photo.jpg', 'original');

// Custom dynamic transformation
$customUrl = $imageManager->getUri('uploads/photo.jpg', 'custom', 'cover:300,200');
```

In a Plates/Latte template:

```html
<img src="<?= $imageManager->getUri($post->cover_image, 'thumb') ?>" alt="Thumbnail">
<img src="<?= $imageManager->getUri($post->cover_image, 'medium') ?>" alt="Medium">
```

---

## Templates

Templates define how images are transformed. There are three types:

### Closure Templates

Define inline in the config:

```php
'templates' => [
    'thumb' => function ($image) {
        return $image->cover(width: 150, height: 150);
    },
    'banner' => function ($image) {
        return $image->cover(width: 1200, height: 400);
    },
    'avatar' => function ($image) {
        return $image->cover(width: 80, height: 80)->greyscale();
    },
],
```

### Class Templates

Implement `Intervention\Image\Interfaces\ModifierInterface`:

```php
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;

class WatermarkTemplate implements ModifierInterface
{
    public function apply(ImageInterface $image): ImageInterface
    {
        return $image
            ->scale(width: 800)
            ->place('storage/watermark.png', 'bottom-right', 10, 10);
    }
}
```

Register in config:

```php
'templates' => [
    'watermark' => WatermarkTemplate::class,
],
```

### Special Templates

| Template | Description |
|----------|-------------|
| `original` | Returns the original image without transformation |
| `download` | Returns the original image with a `Content-Disposition: attachment` header |

---

## Custom Template (Dynamic Transformations)

The built-in `CustomTemplate` class allows dynamic transformations via URL options. The options string is passed as the third argument to `getUri()`:

```php
$url = $imageManager->getUri('photo.jpg', 'custom', 'cover:300,200');
$url = $imageManager->getUri('photo.jpg', 'custom', 'resize:500,null');
$url = $imageManager->getUri('photo.jpg', 'custom', 'resize:800,600|cover:400,400');
```

### Syntax

```
method:arg1,arg2/constraint1/constraint2|method2:arg1,arg2
```

- `|` separates multiple operations (applied in order)
- `:` separates the method name from arguments
- `,` separates arguments
- `/` after arguments adds constraints

### Supported Methods

Any method available on Intervention Image's `ImageInterface`:

| Method | Arguments | Example |
|--------|-----------|---------|
| `resize` | width, height | `resize:500,300` |
| `cover` | width, height, position | `cover:300,200` |
| `scale` | width, height | `scale:800,null` |

### Constraints

| Code | Constraint | Description |
|------|-----------|-------------|
| `ar` | `aspectRatio` | Keep aspect ratio (resize proportionally) |
| `up` | `upsize` | Prevent upsizing beyond original dimensions |

### Examples

```php
// Resize to 500x300, keep aspect ratio
$imageManager->getUri('photo.jpg', 'custom', 'resize:500,300/ar');

// Cover 200x200, then apply another operation
$imageManager->getUri('photo.jpg', 'custom', 'cover:200,200|resize:100,100');
```

---

## Caching

Transformed images are cached to the filesystem. On the first request, the image is processed and saved. Subsequent requests for the same URL serve the cached file.

### FileSystem Cache

The default cache stores files in the `public/` directory under the `basePath`:

```
public/i/a3f2/...rest_of_token.jpg
```

The first 4 characters of the token become a subdirectory to avoid too many files in one folder.

### Cache Headers

Responses include:
- `Cache-Control: max-age={lifetime * 60}, public`
- `ETag` based on the image content MD5
- `304 Not Modified` when the browser sends a matching `If-None-Match`

### Web Server Optimization

Since cached images are saved as static files in `public/`, your web server (Nginx/Apache) can serve them directly without hitting PHP on subsequent requests. Configure your web server to try static files first:

```nginx
location /i/ {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Custom Cache Implementation

Implement `ImageCacheInterface`:

```php
use ON\Image\Cache\ImageCacheInterface;

class S3Cache implements ImageCacheInterface
{
    public function get($token, $template, $path) { /* ... */ }
    public function filename($path, $token) { /* ... */ }
    public function token($path) { /* ... */ }
}
```

---

## Encrypters

The URL token is encrypted to prevent tampering. Two encrypters are available:

### OpenSSL (Default)

Uses AES-128-CBC encryption. Fast and lightweight.

```php
'encrypter' => [
    'class' => \ON\Image\Encrypter\OpenSSL::class,
],
```

### JWT

Uses HMAC-SHA512 signed JWT tokens. Requires `lcobucci/jwt`:

```bash
composer require lcobucci/jwt
```

```php
'encrypter' => [
    'class' => \ON\Image\Encrypter\Jwt::class,
],
```

JWT tokens are longer but can be validated without decryption (signature verification only).

### Custom Encrypter

Implement `EncrypterInterface`:

```php
use ON\Image\Encrypter\EncrypterInterface;

class MyEncrypter implements EncrypterInterface
{
    public function encrypt($data): ?string { /* ... */ }
    public function decrypt($token): mixed { /* ... */ }
}
```

---

## 404 Fallback Image

When the source image file doesn't exist, the extension uses the `404ImagePath` config value as a fallback. This prevents broken images in the UI — instead of a 404, the user sees a placeholder.

```php
'404ImagePath' => 'storage/placeholder.png',
```

---

## See Also

- [Intervention Image v3 Documentation](https://image.intervention.io/v3)
- [View Extension](views.md) — Template engines for rendering HTML with image URLs
