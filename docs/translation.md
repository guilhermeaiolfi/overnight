# Translation (i18n)

Overnight provides internationalization support with locale management and translation services.

## Configuration

```php
use ON\Translation\TranslationExtension;

$app->install(new TranslationExtension([
    'default_locale' => 'en',
    'default_domain' => 'messages',
    'locales' => ['en', 'es', 'fr', 'de'],
]));
```

## Translation Files

### Directory Structure

```
translations/
├── messages.en.php
├── messages.es.php
├── messages.fr.php
├── validation.en.php
└── validation.es.php
```

### Translation File Format

```php
<?php
// translations/messages.en.php

return [
    'hello' => 'Hello',
    'goodbye' => 'Goodbye',
    'welcome_user' => 'Welcome, {name}!',
    'items_count' => '{count} item|{count} items',
];
```

```php
<?php
// translations/messages.es.php

return [
    'hello' => 'Hola',
    'goodbye' => 'Adiós',
    'welcome_user' => '¡Bienvenido, {name}!',
    'items_count' => '{count} elemento|{count} elementos',
];
```

## Basic Usage

```php
$t = $app->ext('translation');

// Simple translation
echo $t->_('hello');  // "Hello" or "Hola" depending on locale

// With parameter
echo $t->_('welcome_user', null, null, ['name' => 'John']);
// "Welcome, John!"
```

## Translation Methods

### Translate

```php
// Translate message
$t->_('message.key');

// With domain
$t->_('message.key', 'validation');

// With parameters
$t->_('welcome_user', null, null, ['name' => 'John']);
```

### Plural Translation

```php
// $count = 1 → "1 item"
// $count = 5 → "5 items"

$t->__n('item', 'items', $count);
// Or with key
$t->__n('items_count', null, null, ['count' => $count], $count);
```

### Domain

Set default domain for translations:

```php
// Set default domain
$t->setDefaultDomain('validation');

// Translate from different domain
$t->_('required', 'forms');
```

## Locale Management

### Setting Locale

```php
$t = $app->ext('translation');

// Set locale
$t->setLocale('es');

// Get current locale
$locale = $t->getCurrentLocale();
// "es"

// Get locale identifier
$identifier = $t->getLocaleIdentifier();
// "es_ES"
```

### Locale Detection

```php
// Auto-detect from request
$locale = $t->getMatchingLocaleIdentifiers($request);

// Available locales
$available = $t->getAvailableLocales();
// ['en', 'es', 'fr', 'de']
```

### Locale Fallback

```php
// If 'es_MX' not found, falls back to 'es'
$translated = $t->_('hello');

// Manual fallback
$translated = $t->_('hello', null, 'es');
```

## Number Formatting

```php
$t = $app->ext('translation');

// Number
echo $t->_n(1234.56, 'numbers');
// "1,234.56" in English, "1.234,56" in German

// Currency
echo $t->_c(1234.56, 'prices', 'USD');
// "$1,234.56" or "1.234,56 $"

$t->setLocale('de');
echo $t->_c(1234.56, 'prices', 'EUR');
// "1.234,56 €"
```

## Date Formatting

```php
$t = $app->ext('translation');

$date = new \DateTime('2024-01-15');

// Short date
echo $t->_d($date);
// "01/15/2024" or "15.01.2024"

// Medium date
echo $t->_d($date, null, 'medium');

// Full date
echo $t->_d($date, null, 'full');

// Custom format
echo $date->format($t->getDateFormat('short'));
```

## Domain Organization

Group translations by domain:

```php
// validation.php
return [
    'required' => 'This field is required',
    'email' => 'Please enter a valid email',
];

// messages.php
return [
    'welcome' => 'Welcome!',
    'goodbye' => 'Goodbye!',
];
```

## In Pages

```php
class ContactPage extends AbstractPage
{
    public function form(): Response
    {
        $t = $this->container->get(TranslationManagerInterface::class);
        
        return $this->render('contact/form', [
            'labels' => [
                'name' => $t->_('validation.name'),
                'email' => $t->_('validation.email'),
                'submit' => $t->_('contact.submit'),
            ],
        ]);
    }

    public function submit(ServerRequestInterface $request): Response
    {
        $t = $this->container->get(TranslationManagerInterface::class);
        $data = $request->getParsedBody();
        
        $errors = [];
        
        if (empty($data['name'])) {
            $errors['name'] = $t->_('validation.required');
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $t->_('validation.email.invalid');
        }
        
        // ...
    }
}
```

## In Templates

### Plates

```php
<!-- translations/messages.en.php -->
<?php return ['greeting' => 'Hello, {name}!']; ?>

<!-- template.php -->
<p><?= $this->e($this->_('greeting', null, null, ['name' => $user->name])) ?></p>
```

### Latte

```latte
<!-- template.latte -->
<p>{_'greeting', name => $user->name}</p>
```

## Translation Manager

```php
use ON\Translation\TranslationManager;

$t = new TranslationManager([
    'default_locale' => 'en',
    'locales' => ['en', 'es', 'fr'],
]);

// Add translator
$t->addTranslator($translator, 'messages');

// Set locale
$t->setLocale('es');

// Translate
echo $t->_('hello');
```

## Best Practices

1. **Use keys, not strings** - `validation.required` not "Required field"
2. **Organize by domain** - Separate validation, messages, emails
3. **Use parameters** - Avoid concatenation: `Hello, {name}!`
4. **Plan plurals** - Different languages have different plural rules
5. **Fallback locale** - Always have a fallback (English usually)
6. **Separate files** - One file per locale per domain
