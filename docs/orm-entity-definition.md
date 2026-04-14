# ORM Entity Definition

This document describes how to define entities (collections) in the Overnight framework using the fluent builder pattern.

## Table of Contents

1. [Registry - Entry Point](#registry---entry-point)
2. [Collection Definition](#collection-definition)
3. [Field Definition](#field-definition)
4. [Relation Definition](#relation-definition)
5. [The ->end() Rule](#the---end---rule)
6. [Metadata System](#metadata-system)
7. [Complete Example](#complete-example)
8. [Converting to Other Schemas](#converting-to-other-schemas)

---

## Registry - Entry Point

The `Registry` class is the main entry point for defining collections (entities):

```php
use ON\ORM\Definition\Registry;

$registry = new Registry();

$collection = $registry->collection("user");
```

### Getting a Collection

```php
// Returns ?CollectionInterface (null if not found)
$collection = $registry->getCollection("user");
```

---

## Collection Definition

A Collection represents a database table/entity:

```php
$registry->collection("user")
    ->field("id", "int")->primaryKey(true)->end()
    ->field("name", "string")->end()
    ->field("email", "string")->end()
    ->table("users")                    // custom table name (optional)
    ->entity(EntityClass::class)        // entity class (optional)
    ->database("default")               // database name (optional)
    ->hidden(false)                     // hide from schema generation
    ->end();
```

### Collection Properties

| Method | Description | Default |
|--------|-------------|---------|
| `name(string)` | Collection name (used as role) | Required |
| `table(string)` | Database table name | Same as name |
| `entity(string)` | Entity class name | `stdClass` |
| `database(string)` | Database name | `"default"` |
| `mapper(string)` | Mapper class | `Cycle\ORM\Mapper\StdMapper` |
| `source(string)` | Source class | `ON\ORM\Select\Source` |
| `hidden(bool)` | Hide from schema generation | `false` |

Primitive properties are protected with getters/setters. The `fields` and `relations` maps are public:

```php
$collection->fields;    // FieldMap (public)
$collection->relations; // RelationMap (public)
```

---

## Field Definition

A Field represents a database column:

```php
$registry->collection("user")
    ->field("id", "int")
        ->primaryKey(true)
        ->autoIncrement(true)
        ->end()
    ->field("name", "string")
        ->maxLength(255)
        ->nullable(false)
        ->default("john")
        ->unique(false)
        ->indexed(false)
        ->type("varchar")
        ->comment("User's full name")
        ->hidden(false)
        ->end();
```

### Field Methods

| Method | Description |
|--------|-------------|
| `primaryKey(bool)` | Mark as primary key |
| `autoIncrement(bool)` | Enable auto-increment |
| `nullable(bool)` | Allow NULL values |
| `default(mixed)` | Set default value |
| `castDefault(bool)` | Typecast default value |
| `unique(bool)` | Add unique constraint |
| `indexed(bool)` | Add index |
| `maxLength(int)` | Set maximum length |
| `dataType(string)` | Set database data type |
| `numericPrecision(int)` | Set numeric precision |
| `comment(string)` | Set column comment |
| `hidden(bool)` | Hide from output |
| `typecast(string\|callable)` | Set typecast handler |
| `validation(string)` | Set validation rules (pipe syntax) |

### Field Validation

Fields support validation rules using `somnambulist/validation` pipe syntax:

```php
$registry->collection("user")
    ->field("name", "string")->validation('required|max:255')->end()
    ->field("email", "string")->validation('required|email|max:255')->end()
    ->field("age", "int")->validation('min:0|max:150')->end()
    ->end();
```

Validation is used by the [GraphQL extension](extensions/graphql.md) to validate mutation input.

### Field Type Shorthand

The `field()` method accepts an optional type as the second argument:

```php
// These are equivalent:
->field('name', 'string')
->field('name')->type('string')
```

---

## Relation Definition

### Convenience Relation Methods

The `Collection` class provides shorthand methods for defining relations without importing relation classes:

```php
$registry->collection("user")
    ->hasMany("posts", "post")       // HasManyRelation
    ->hasOne("profile", "profile")   // HasOneRelation
    ->end();

$registry->collection("post")
    ->belongsTo("author", "user")    // BelongsToRelation
    ->end();
```

Each method takes the relation name and target collection name, and returns the relation object for further configuration.

### HasMany

```php
$registry->collection("user")
    ->hasMany("posts", "post")
        ->load('eager')           // load strategy: 'lazy' or 'eager'
        ->cascade(true)           // cascade operations
        ->innerKey('user_id')     // inner key (this side)
        ->outerKey('id')          // outer key (target side)
        ->end();
```

### HasOne

```php
$registry->collection("user")
    ->hasOne("profile", "profile")
        ->nullable(true)
        ->cascade(true)
        ->innerKey('user_id')
        ->outerKey('id')
        ->end();
```

### BelongsTo

```php
$registry->collection("post")
    ->belongsTo("author", "user")
        ->nullable(true)
        ->innerKey('user_id')
        ->outerKey('id')
        ->end();
```

### ManyToMany (M2M)

```php
$registry->collection("post")
    ->manyToMany("tags", "tag")
        ->through("post_tags")    // pivot table
        ->innerKey('post_id')
        ->outerKey('tag_id')
        ->end();
```

### Relation Methods

| Method | Description | Default |
|--------|-------------|---------|
| `load(string)` | Load strategy (`lazy` or `eager`) | `"lazy"` |
| `cascade(bool)` | Cascade operations | `true` |
| `nullable(bool)` | Allow nullable relation | `false` |
| `innerKey(string)` | Key on the source side | Generated |
| `outerKey(string)` | Key on the target side | Generated |
| `exclusive(bool)` | Exclusive relation | `false` |

---

## The ->end() Rule

**Critical Concept:** Methods return child objects. Use `->end()` to return to the parent.

The fluent builder follows this pattern:
- `->field()` returns `Field` object
- `->hasMany()`, `->hasOne()`, etc. return `Relation` object
- Call `->end()` to return to the parent `Collection`
- Call `Collection->end()` to return to the `Registry`

```php
// CORRECT - use ->end() to navigate back
$registry->collection("user")
    ->field("id", "int")->primaryKey(true)->end()   // returns to Collection
    ->field("name", "string")->end()                // returns to Collection
    ->hasMany("posts", "post")                      // returns to Relation
        ->cascade(true)->end()                       // returns to Relation
        ->end();                                     // returns to Collection

// WRONG - missing ->end() leaves you in wrong context
$registry->collection("user")
    ->field("id", "int")->primaryKey(true)  // stuck in Field!
```

### ->end() Return Chain

- `Field.end()` → `Collection`
- `Relation.end()` → `Collection`
- `Collection.end()` → `Registry`

---

## Metadata System

The Metadata system allows storing custom data on Collection, Field, or Relation objects. This is useful for adding framework-specific information (like GraphQL resolvers) without modifying the core ORM classes.

### API

The `metadata()` method is both getter and setter:

```php
// Set metadata (returns $this for chaining)
$object->metadata('key', 'value');

// Get metadata (pass only the key)
$value = $object->metadata('key');

// Get metadata with fallback
$value = $object->metadata('key') ?? 'default_value';
```

### Usage with GraphQL

```php
// Set metadata on Collection
$registry->collection("user")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\User::class)->findAll();
    })->end()
    ->metadata('gql::resolver::findById', function($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\User::class)->findByPK($args['id']);
    })->end();

// Set metadata on Field (e.g., type override)
$registry->collection("user")
    ->field("email", "string")
        ->metadata('gql::type', 'EmailType!')->end()
        ->metadata('gql::resolver', function($source, $args, $container) {
            $currentUser = $container->get('current_user');
            return $currentUser->isAdmin() ? $source->email : null;
        })->end()
        ->end();

// Set metadata on Relation
$registry->collection("user")
    ->hasMany("posts", "post")
        ->metadata('gql::resolver', function($user, $args, $container) {
            return $container->get(\Cycle\ORM\ORMInterface::class)
                ->getRepository(\App\Models\Post::class)
                ->findOne(['user_id' => $user->id]);
        })->end()
        ->end();
```

### Reserved Metadata Keys (GraphQL)

| Key | Target | Description |
|-----|--------|-------------|
| `gql::resolver::findAll` | Collection | Collection query resolver |
| `gql::resolver::findById` | Collection | Single item query resolver |
| `gql::resolver::create` | Collection | Create mutation resolver |
| `gql::resolver::update` | Collection | Update mutation resolver |
| `gql::resolver::delete` | Collection | Delete mutation resolver |
| `gql::resolver` | Field/Relation | Field or relation resolver |
| `gql::type` | Field | Override inferred GraphQL type |

### Resolver Signature

Collection/Query resolvers:
```php
function(array $args, Psr\Container\ContainerInterface $container, ?object $context = null): mixed
```

Field/Relation resolvers:
```php
function(mixed $source, array $args, Psr\Container\ContainerInterface $container, ?object $context = null): mixed
```

---

## Complete Example

```php
// config/orm.php

use ON\ORM\Definition\Registry;

$registry = new Registry();

$registry->collection("user")
    // Fields
    ->field("id", "int")->primaryKey(true)->autoIncrement(true)->end()
    ->field("name", "string")->maxLength(255)->validation('required|max:255')->end()
    ->field("email", "string")->maxLength(255)->unique(true)->validation('required|email|max:255')->end()
    ->field("password", "string")->hidden(true)->end()
    ->field("created_at", "datetime")->end()
    
    // Relations
    ->hasMany("posts", "post")
        ->cascade(true)->load('lazy')->end()
        ->end()
    ->hasOne("profile", "profile")
        ->nullable(true)->cascade(true)->end()
        ->end()
    
    ->end();

$registry->collection("post")
    ->field("id", "int")->primaryKey(true)->autoIncrement(true)->end()
    ->field("title", "string")->maxLength(255)->validation('required|max:255')->end()
    ->field("content", "text")->end()
    ->field("user_id", "int")->end()
    ->field("created_at", "datetime")->end()
    
    ->belongsTo("author", "user")
        ->innerKey('user_id')->outerKey('id')->end()
        ->end()
    
    ->end();
```

---

## Converting to Other Schemas

The Registry can be converted to other schema formats (like Cycle ORM) using generators:

```php
// Convert to Cycle ORM Schema
use Cycle\Schema\Registry as CycleRegistry;
use ON\ORM\Compiler\CycleRegistryGenerator;

$cycleRegistry = new CycleRegistry($dbal);
$generator = new CycleRegistryGenerator($ormRegistry);
$schema = (new \Cycle\ORM\Compiler())->compile($cycleRegistry, [
    new CycleRegistryGenerator($registry),
    // ... other generators
]);

// Convert to GraphQL Schema
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\GraphQL\Resolver\SqlResolver;

$resolver = new SqlResolver($ormRegistry, $database);
$graphqlGenerator = new GraphQLRegistryGenerator($ormRegistry, $container, $resolver);
$graphqlSchema = $graphqlGenerator->generate();
```

### Adding New Generators

To create a new generator (e.g., for REST, JSON:API), follow this pattern:

```php
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Collection\Collection;

class MySchemaGenerator
{
    public function __construct(
        protected Registry $registry
    ) {
    }

    public function generate(): mixed
    {
        $schema = [];
        
        foreach ($this->registry->getCollections() as $collection) {
            $schema[$collection->name] = $this->convertCollection($collection);
        }
        
        return $schema;
    }

    protected function convertCollection(Collection $collection): array
    {
        return [
            'name' => $collection->name,
            'table' => $collection->getTable(),
            'fields' => $this->convertFields($collection),
            'relations' => $this->convertRelations($collection),
            'metadata' => $collection->allMetadata(),
        ];
    }
}
```

---

## See Also

- [GraphQL Extension Documentation](extensions/graphql.md)
- [Testing Guide](./testing.md)
- [Cycle ORM Documentation](https://cycle-orm.dev/)
- [GraphQL PHP Library](https://webonyx.github.io/graphql-php/)