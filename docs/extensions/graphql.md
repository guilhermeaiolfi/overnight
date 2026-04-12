# GraphQL Extension

The GraphQL extension provides GraphQL API support for the Overnight framework. It automatically generates GraphQL types and resolvers from your ORM entity definitions.

## Table of Contents

1. [Installation](#installation)
2. [Quick Start](#quick-start)
3. [Entity Definition with Resolvers](#entity-definition-with-resolvers)
4. [Resolver Patterns](#resolver-patterns)
5. [Field Type Overrides](#field-type-overrides)
6. [N+1 Problem Solutions](#n1-problem-solutions)
7. [Configuration](#configuration)
8. [API Reference](#api-reference)

---

## Installation

The GraphQL extension requires `webonyx/graphql-php`. Add it to your dependencies:

```bash
composer require webonyx/graphql-php
```

Then install the extension in your application:

```php
// config/extensions.php

use ON\GraphQL\Extension\GraphQLExtension;

$app->install(GraphQLExtension::class);

// Or with options
$app->install(GraphQLExtension::class, [
    'path' => '/graphql',
    'enabled' => true,
]);
```

---

## Quick Start

### Step 1: Define Your Entities

```php
// config/orm.php

use ON\ORM\Definition\Registry;

$registry = new Registry();

$registry->collection("user")
    ->field("id", "int")->primaryKey(true)->end()
    ->field("name", "string")->end()
    ->field("email", "string")->end()
    ->hasMany("posts", "post")
        ->end()
    ->end();

$registry->collection("post")
    ->field("id", "int")->primaryKey(true)->end()
    ->field("title", "string")->end()
    ->field("content", "text")->end()
    ->field("user_id", "int")->end()
    ->belongsTo("user", "user")
        ->innerKey('user_id')->outerKey('id')->end()
        ->end()
    ->end();
```

### Step 2: Add Resolvers

```php
// Continue from above...

$registry->collection("user")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\User::class)->findAll();
    })->end()
    ->metadata('gql::resolver::findById', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\User::class)->findByPK($args['id']);
    })->end()
    // Relation resolver
    ->hasMany("posts", "post")
        ->metadata('gql::resolver', function($user, $args, $container) {
            $orm = $container->get(\Cycle\ORM\ORMInterface::class);
            return iterator_to_array(
                $orm->getRepository(\App\Models\Post::class)->findAll(['user_id' => $user->id])
            );
        })->end()
        ->end()
    ->end();

$registry->collection("post")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\Post::class)->findAll();
    })->end()
    ->metadata('gql::resolver::findById', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\Post::class)->findByPK($args['id']);
    })->end()
    ->metadata('gql::resolver::create', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        $post = new \App\Models\Post($args['input']);
        $orm->persist($post);
        $orm->run();
        return $post;
    })->end()
    ->end();
```

### Step 3: Query Your API

```
POST /graphql
{
  "query": "{ users { id name email posts { id title } } }"
}
```

---

## Entity Definition with Resolvers

### Collection-Level Resolvers

| Metadata Key | Purpose | Signature |
|--------------|---------|-----------|
| `gql::resolver::findAll` | Collection query resolver | `fn(array $args, ContainerInterface $container, ?object $context): mixed` |
| `gql::resolver::findById` | Single item query resolver | `fn(array $args, ContainerInterface $container, ?object $context): mixed` |
| `gql::resolver::create` | Create mutation resolver | `fn(array $args, ContainerInterface $container, ?object $context): mixed` |
| `gql::resolver::update` | Update mutation resolver | `fn(array $args, ContainerInterface $container, ?object $context): mixed` |
| `gql::resolver::delete` | Delete mutation resolver | `fn(array $args, ContainerInterface $container, ?object $context): mixed` |

### Field/Relation-Level Resolvers

| Metadata Key | Target | Signature |
|--------------|--------|-----------|
| `gql::resolver` | Field or Relation | `fn(mixed $source, array $args, ContainerInterface $container, ?object $context): mixed` |
| `gql::type` | Field only | `string` - Override inferred GraphQL type |

---

## Resolver Patterns

### Basic Collection Resolver

```php
$registry->collection("user")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        $repo = $orm->getRepository(\App\Models\User::class);
        
        // Apply filters from args
        $scope = $args['filter'] ?? [];
        return iterator_to_array($repo->findAll($scope));
    })->end()
    ->end();
```

### Find By ID Resolver

```php
->metadata('gql::resolver::findById', function($args, $container) {
    $orm = $container->get(\Cycle\ORM\ORMInterface::class);
    $repo = $orm->getRepository(\App\Models\User::class);
    
    return $repo->findByPK($args['id']);
})->end()
```

### Create Mutation Resolver

```php
->metadata('gql::resolver::create', function($args, $container) {
    $orm = $container->get(\Cycle\ORM\ORMInterface::class);
    
    $user = new \App\Models\User();
    $user->name = $args['input']['name'] ?? null;
    $user->email = $args['input']['email'] ?? null;
    
    $orm->persist($user);
    $orm->run();
    
    return $user;
})->end()
```

### Field Resolver (e.g., conditional field)

```php
$registry->collection("user")
    ->field("email", "string")
        ->metadata('gql::resolver', function($source, $args, $container) {
            // Only show email to admin users
            $currentUser = $container->get('current_user');
            if ($currentUser && $currentUser->isAdmin()) {
                return $source->email;
            }
            return null;
        })->end()
        ->end()
    ->end();
```

### Relation Resolver (hasMany)

```php
$registry->collection("user")
    ->hasMany("posts", "post")
        ->metadata('gql::resolver', function($user, $args, $container) {
            $orm = $container->get(\Cycle\ORM\ORMInterface::class);
            return iterator_to_array(
                $orm->getRepository(\App\Models\Post::class)->findAll([
                    'user_id' => $user->id
                ])
            );
        })->end()
        ->end()
    ->end();
```

### Relation Resolver (belongsTo/hasOne)

```php
$registry->collection("post")
    ->belongsTo("user", "user")
        ->metadata('gql::resolver', function($post, $args, $container) {
            $orm = $container->get(\Cycle\ORM\ORMInterface::class);
            return $orm->getRepository(\App\Models\User::class)->findByPK($post->user_id);
        })->end()
        ->end()
    ->end();
```

---

## Field Type Overrides

Override the inferred GraphQL type using `gql::type`:

```php
$registry->collection("user")
    ->field("email", "string")
        ->metadata('gql::type', 'EmailType!')->end()
        ->end()
    ->field("created_at", "datetime")
        ->metadata('gql::type', 'DateTime!')->end()
        ->end()
    ->end();
```

### Available Type Formats

- `String` - nullable string
- `String!` - non-nullable string
- `[String]!` - non-nullable list of nullable strings
- `[String!]!` - non-nullable list of non-nullable strings

---

## N+1 Problem Solutions

The N+1 problem occurs when fetching a list of items with relations, causing one query per item.

### Solution 1: Simple Batch Loading

```php
$registry->collection("user")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        $users = iterator_to_array(
            $orm->getRepository(\App\Models\User::class)->findAll()
        );
        
        // Pre-load all posts for these users
        $userIds = array_map(fn($u) => $u->id, $users);
        $posts = iterator_to_array(
            $orm->getRepository(\App\Models\Post::class)->findAll(['user_id' => $userIds])
        );
        
        // Group posts by user_id
        $postsByUser = [];
        foreach ($posts as $post) {
            $postsByUser[$post->user_id][] = $post;
        }
        
        // Attach posts to users
        foreach ($users as $user) {
            $user->posts = $postsByUser[$user->id] ?? [];
        }
        
        return $users;
    })->end()
    ->end();
```

### Solution 2: DataLoader Pattern (Recommended)

Create a DataLoader class:

```php
<?php
// src/GraphQL/DataLoader/PostLoader.php

namespace ON\GraphQL\DataLoader;

use Cycle\ORM\ORMInterface;

class PostLoader
{
    protected array $cache = [];
    protected array $pending = [];

    public function __construct(
        protected ORMInterface $orm
    ) {
    }

    public function load(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $this->pending[$userId] = true;

        return [];
    }

    public function resolve(): void
    {
        if (empty($this->pending)) {
            return;
        }

        $userIds = array_keys($this->pending);

        $posts = iterator_to_array(
            $this->orm->getRepository(\App\Models\Post::class)
                ->findAll(['user_id' => $userIds])
        );

        $grouped = [];
        foreach ($posts as $post) {
            $grouped[$post->user_id][] = $post;
        }

        foreach ($userIds as $userId) {
            $this->cache[$userId] = $grouped[$userId] ?? [];
        }

        $this->pending = [];
    }

    public function getCache(): array
    {
        return $this->cache;
    }
}
```

Register the DataLoader in your container:

```php
// config/container.php

$container->define(\ON\GraphQL\DataLoader\PostLoader::class, function($c) {
    return new \ON\GraphQL\DataLoader\PostLoader(
        $c->get(\Cycle\ORM\ORMInterface::class)
    );
});
```

Use in resolver:

```php
$registry->collection("user")
    ->hasMany("posts", "post")
        ->metadata('gql::resolver', function($user, $args, $container) {
            $loader = $container->get(\ON\GraphQL\DataLoader\PostLoader::class);
            
            // Register user for batch loading
            $loader->load($user->id);
            
            // This is simplified - in production you'd use a defer/promise pattern
            return $loader->getCache()[$user->id] ?? [];
        })->end()
        ->end()
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        $users = iterator_to_array(
            $orm->getRepository(\App\Models\User::class)->findAll()
        );
        
        // Batch load all posts
        $loader = $container->get(\ON\GraphQL\DataLoader\PostLoader::class);
        foreach ($users as $user) {
            $loader->load($user->id);
        }
        $loader->resolve();
        
        return $users;
    })->end()
    ->end();
```

### Solution 3: Cycle ORM Eager Loading

Use Cycle's eager loading for simpler cases:

```php
$registry->collection("user")
    ->hasMany("posts", "post")
        ->load('eager')  // Load all posts with user in single query
        ->end()
    ->end();

// Resolver simply accesses the pre-loaded relation
->metadata('gql::resolver::findAll', function($args, $container) {
    $orm = $container->get(\Cycle\ORM\ORMInterface::class);
    $repo = $orm->getRepository(\App\Models\User::class);
    
    // The select will automatically eager load 'posts' relation
    return iterator_to_array($repo->select()->with('posts')->fetchAll());
})->end()
```

---

## Configuration

### Extension Options

```php
$app->install(\ON\GraphQL\Extension\GraphQLExtension::class, [
    'path' => '/graphql',     // GraphQL endpoint path (default: '/graphql')
    'enabled' => true,        // Enable/disable extension
]);
```

### Middleware

The GraphQL middleware handles:
- `GET` requests with `?query=...&variables=...`
- `POST` requests with `{"query": "...", "variables": {...}}`

---

## API Reference

### GraphQLRegistryGenerator

```php
use ON\GraphQL\GraphQLRegistryGenerator;

$generator = new GraphQLRegistryGenerator($ormRegistry, $container);
$schema = $generator->generate();
```

### GraphQLSchemaFactory

```php
use ON\GraphQL\GraphQLSchemaFactory;
use Psr\Container\ContainerInterface;

$factory = new GraphQLSchemaFactory($container);
$schema = $factory->create($config);
```

### Metadata Keys Reference

| Key | Target | Description |
|-----|--------|-------------|
| `gql::resolver::findAll` | Collection | Resolver for listing collections |
| `gql::resolver::findById` | Collection | Resolver for getting single item |
| `gql::resolver::create` | Collection | Resolver for creating items |
| `gql::resolver::update` | Collection | Resolver for updating items |
| `gql::resolver::delete` | Collection | Resolver for deleting items |
| `gql::resolver` | Field | Resolver for field value |
| `gql::resolver` | Relation | Resolver for relation |
| `gql::type` | Field | Override GraphQL type |


---

## See Also

- [ORM Entity Definition](../orm-entity-definition.md) - How to define entities with metadata
- [GraphQL PHP Library](https://webonyx.github.io/graphql-php/) - Reference documentation
- [GraphQL Specification](https://graphql.org/) - Official GraphQL spec