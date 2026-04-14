# Generic DataLoader

A generic, reusable DataLoader for batching and caching to solve the N+1 problem in GraphQL resolvers.

## Overview

The `GenericDataLoader` handles only **batching** and **caching** - it does not know how to load data from the database. Your resolver provides the loading function, and the DataLoader ensures efficient batch loading.

### Constructor

```php
new GenericDataLoader(
    Registry $registry,
    int $maxCacheSize = 10000  // LRU eviction when exceeded
);
```

The `$maxCacheSize` parameter controls the maximum number of cached entries. When the cache exceeds this limit, the oldest 10% of entries are evicted (LRU strategy).

### Why Use It?

Without DataLoader (N+1 problem):

```
Query: { users { posts { id } } }

SQL: SELECT * FROM users                        -- 1 query
SQL: SELECT * FROM posts WHERE user_id = 1       -- N queries (one per user)
SQL: SELECT * FROM posts WHERE user_id = 2
SQL: SELECT * FROM posts WHERE user_id = 3
...
```

With DataLoader:

```
Query: { users { posts { id } }

SQL: SELECT * FROM users                    -- 1 query
SQL: SELECT * FROM posts WHERE user_id IN (1, 2, 3, ...)  -- 1 batch query
```

---

## Setup

### Register in Container

```php
// config/container.php

use ON\GraphQL\DataLoader\GenericDataLoader;

$container->define(GenericDataLoader::class, function($c) {
    return new GenericDataLoader(
        $c->get(\ON\ORM\Definition\Registry::class),
        maxCacheSize: 10000  // default, LRU eviction when exceeded
    );
});
```

### Clear After Each Request

```php
// In your GraphQL middleware or request handler

$loader = $container->get(GenericDataLoader::class);
$loader->clear();
```

---

## API Reference

### Methods

| Method | Description |
|--------|------------|
| `load($entity, $keys, $loaderFn)` | Load single entity; cached or batched |
| `loadMany($entity, $keys, $loaderFn)` | Load multiple entities batched |
| `results($entity)` | Get all cached results for entity |
| `has($entity, $key)` | Check if key is cached (uses `array_key_exists`, handles `null` values) |
| `get($entity, $key)` | Get cached value |
| `set($entity, $key, $value)` | Set cache value manually |
| `clear()` | Clear entire cache |
| `clearEntity($entity)` | Clear cache for specific entity |
| `getPendingKeys($entity)` | Get pending keys for entity |

---

## Usage Examples

### 1. Simple Collection Query

```php
$registry->collection("user")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
        
        return $loader->load('user', 'all', function($keys) use ($container, $args) {
            $orm = $container->get(\Cycle\ORM\ORMInterface::class);
            $repo = $orm->getRepository(\App\Models\User::class);
            
            return iterator_to_array($repo->findAll($args['filter'] ?? []));
        });
    })->end()
    ->end();
```

### 2. Find By ID

```php
$registry->collection("user")
    ->metadata('gql::resolver::findById', function($args, $container) {
        $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
        
        return $loader->load('user', ['id' => $args['id']], function($keys) use ($container) {
            $orm = $container->get(\Cycle\ORM\ORMInterface::class);
            $repo = $orm->getRepository(\App\Models\User::class);
            
            $ids = array_column($keys, 'id');
            return iterator_to_array($repo->findAll(['id' => $ids]));
        });
    })->end()
    ->end();
```

### 3. HasMany Relation

```php
$registry->collection("user")
    ->hasMany("posts", "post")
        ->metadata('gql::resolver', function($user, $args, $container) {
            $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
            
            return $loader->load('post', ['user_id' => $user->id], function($keys) use ($container) {
                $orm = $container->get(\Cycle\ORM\ORMInterface::class);
                $repo = $orm->getRepository(\App\Models\Post::class);
                
                $userIds = array_column($keys, 'user_id');
                return iterator_to_array($repo->findAll(['user_id' => $userIds]));
            });
        })->end()
        ->end()
    ->end();
```

### 4. BelongsTo Relation

```php
$registry->collection("post")
    ->belongsTo("author", "user")
        ->metadata('gql::resolver', function($post, $args, $container) {
            $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
            
            return $loader->load('user', ['id' => $post->user_id], function($keys) use ($container) {
                $orm = $container->get(\Cycle\ORM\ORMInterface::class);
                $repo = $orm->getRepository(\App\Models\User::class);
                
                $ids = array_column($keys, 'id');
                return iterator_to_array($repo->findAll(['id' => $ids]));
            });
        })->end()
        ->end()
    ->end();
```

### 5. Composite Key Relation (Junction Table)

```php
$registry->collection("user")
    ->hasMany("user_roles", "user_role")
        ->metadata('gql::resolver', function($user, $args, $container) {
            $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
            
            // Composite key: user_id + role_id
            return $loader->load('user_role', ['user_id' => $user->id], function($keys) use ($container) {
                $orm = $container->get(\Cycle\ORM\ORMInterface::class);
                $repo = $orm->getRepository(\App\Models\UserRole::class);
                
                $userIds = array_column($keys, 'user_id');
                return iterator_to_array($repo->findAll(['user_id' => $userIds]));
            });
        })->end()
        ->end()
    ->end();
```

### 6. Batch With Pre-loading

For maximum efficiency, pre-load relations when fetching the collection:

```php
$registry->collection("post")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        
        // Load all posts
        $posts = iterator_to_array(
            $orm->getRepository(\App\Models\Post::class)->findAll($args['filter'] ?? [])
        );
        
        // Pre-load all authors for these posts (batch)
        $userIds = array_filter(array_map(fn($p) => $p->user_id ?? null, $posts));
        if (!empty($userIds)) {
            $loader->load('user', array_values(array_unique($userIds)), function($keys) use ($container) {
                $orm = $container->get(\Cycle\ORM\ORMInterface::class);
                $ids = array_column($keys, 'id');
                
                return iterator_to_array(
                    $orm->getRepository(\App\Models\User::class)
                        ->findAll(['id' => $ids])
                );
            });
        }
        
        return $posts;
    })->end()
    ->belongsTo("author", "user")
        ->metadata('gql::resolver', function($post, $args, $container) {
            $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
            return $loader->get('user', ['id' => $post->user_id]);
        })->end()
        ->end()
    ->end();
```

---

## Primary Key Handling

The DataLoader automatically detects primary keys from your Collection definition:

```php
$registry->collection("user")
    ->field("id", "int")->primaryKey(true)->end()
    ->field("name", "string")->end()
    ->end();

// Key format: "user:id:5"
```

### Composite Keys

For tables with composite primary keys:

```php
$registry->collection("user_role")
    ->field("user_id", "int")->primaryKey(true)->end()
    ->field("role_id", "int")->primaryKey(true)->end()
    ->field("user_id", "int")->end()
    ->field("role_id", "int")->end()
    ->end();

// Key format: "user_role:role_id:2:user_id:1" (sorted alphabetically)
```

### Falls Back to `id`

If no Collection is defined, defaults to `id`:

```php
// No collection definition → uses "id"
$loader->load('unknown_entity', 5);
// Key: "unknown_entity:id:5"
```

---

## Async/Deferred

All `load` methods return a `GraphQL\Deferred` automatically:

```php
$result = $loader->load('user', 5, $loaderFn);

// $result is a Deferred - use like:
return $result->then(function($user) {
    return $user;
});
```

The Deferred ensures batch loading happens efficiently after all fields are resolved.

---

## Request Lifecycle

### 1. Before Query

```php
// Create fresh loader instance or clear existing
$loader = $container->get(GenericDataLoader::class);
$loader->clear();
```

### 2. During Query

The loader batches and caches automatically.

### 3. After Query

```php
// Clear to prevent stale data in next request
$loader->clear();
```

---

## Best Practices

1. **Always clear after each request** - Prevents memory leaks and stale data

2. **Use in conjunction with findAll** - Pre-load collection then use cached for relations

3. **Keep loaders request-scoped** - Create new instance per request or clear existing

4. **Return Deferred directly** - Don't await in resolver; let GraphQL handle it

5. **Batch similar requests** - Loading users from posts will batch into single query

---

## Full Example: Blog API

```php
<?php
// config/orm.php

use ON\ORM\Definition\Registry;

$registry = new Registry();

// User collection
$registry->collection("user")
    ->field("id", "int")->primaryKey(true)->end()
    ->field("name", "string")->end()
    ->field("email", "string")->end()
    ->hasMany("posts", "post")
        ->metadata('gql::resolver', function($user, $args, $container) {
            $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
            return $loader->load('post', ['user_id' => $user->id], function($keys) use ($container) {
                $orm = $container->get(\Cycle\ORM\ORMInterface::class);
                $userIds = array_column($keys, 'user_id');
                return iterator_to_array(
                    $orm->getRepository(\App\Models\Post::class)
                        ->findAll(['user_id' => $userIds])
                );
            });
        })->end()
        ->end()
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return iterator_to_array(
            $orm->getRepository(\App\Models\User::class)->findAll()
        );
    })->end()
    ->metadata('gql::resolver::findById', function($args, $container) {
        $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
        return $loader->load('user', ['id' => $args['id']], function($keys) use ($container) {
            $orm = $container->get(\Cycle\ORM\ORMInterface::class);
            $ids = array_column($keys, 'id');
            return iterator_to_array(
                $orm->getRepository(\App\Models\User::class)
                    ->findAll(['id' => $ids])
            );
        });
    })->end()
    ->end();

// Post collection
$registry->collection("post")
    ->field("id", "int")->primaryKey(true)->end()
    ->field("title", "string")->end()
    ->field("content", "text")->end()
    ->field("user_id", "int")->end()
    ->belongsTo("author", "user")
        ->innerKey('user_id')->outerKey('id')->end()
        ->metadata('gql::resolver', function($post, $args, $container) {
            $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
            return $loader->load('user', ['id' => $post->user_id], function($keys) use ($container) {
                $orm = $container->get(\Cycle\ORM\ORMInterface::class);
                $ids = array_column($keys, 'id');
                return iterator_to_array(
                    $orm->getRepository(\App\Models\User::class)
                        ->findAll(['id' => $ids])
                );
            });
        })->end()
        ->end()
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $loader = $container->get(\ON\GraphQL\DataLoader\GenericDataLoader::class);
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        
        $posts = iterator_to_array(
            $orm->getRepository(\App\Models\Post::class)->findAll($args['filter'] ?? [])
        );
        
        // Pre-load authors
        $userIds = array_filter(array_map(fn($p) => $p->user_id ?? null, $posts));
        if (!empty($userIds)) {
            $loader->load('user', array_values(array_unique($userIds)), function($keys) use ($container) {
                $orm = $container->get(\Cycle\ORM\ORMInterface::class);
                $ids = array_column($keys, 'id');
                return iterator_to_array(
                    $orm->getRepository(\App\Models\User::class)
                        ->findAll(['id' => $ids])
                );
            });
        }
        
        return $posts;
    })->end()
    ->end();
```

Then query:

```graphql
{
  posts {
    id
    title
    author {
      id
      name
    }
  }
}
```

Makes only 2 queries:
1. `SELECT * FROM posts`
2. `SELECT * FROM users WHERE id IN (1, 2, 3, ...)`

---

## See Also

- [GraphQL Extension](graphql.md) - Integration with ORM
- [ORM Entity Definition](../orm-entity-definition.md) - Defining collections
- [webonyx/graphql-php Deferred](https://webonyx.github.io/graphql-php/data-fetching/) - Deferred resolution