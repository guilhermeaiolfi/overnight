# ORM Entity Definition

This document describes how to define entities (collections) in the Overnight framework using the ON\Data fluent builder. The sole registry is `ON\Data\Definition\Registry` (package `guilhermeaiolfi/overnight-data`).

## Table of Contents

1. [Registry - Entry Point](#registry---entry-point)
2. [Collection Definition](#collection-definition)
3. [Field Definition](#field-definition)
4. [Field Type Handlers and Cycle Schema](#field-type-handlers-and-cycle-schema)
5. [Relation Definition](#relation-definition)
6. [The ->end() Rule](#the---end---rule)
7. [Metadata System](#metadata-system)
8. [Complete Example](#complete-example)
9. [Converting to Other Schemas](#converting-to-other-schemas)

---

## Registry - Entry Point

The `Registry` class is the main entry point for defining collections (entities). In a running application, resolve it from the container (`Registry::class`). It is provided by `DefinitionRegistryProvider`, which emits `DataDefinitionConfigureEvent` on a cold cache miss (or whenever the app is in debug mode) and otherwise loads `data-definitions.php`.

### Event-Based Registration (Recommended)

Listen to `DataDefinitionConfigureEvent` from an extension's `register()` method:

```php
<?php

use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

final class AppDefinitionsExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        $init->on(DataDefinitionConfigureEvent::class, function (DataDefinitionConfigureEvent $event): void {
            $event->registry
                ->collection('user')
                ->primaryKey('id')
                ->field('id', 'int')->autoIncrement(true)->end()
                ->field('name', 'string')->end()
                ->end();
        });
    }
}
```

See [ON\Data Definition Architecture](ondata-cycle-schema-migration.md) for cold/warm cache details.

### Manual Initialization

If you are using the Registry in a standalone script or test:

```php
use ON\Data\Definition\Registry;

$registry = new Registry();
$collection = $registry->collection('user');
```

### Getting a Collection

```php
// Returns ?CollectionInterface (null if not found)
$collection = $registry->getCollection('user');
```

---

## Collection Definition

A Collection represents a database table/entity:

```php
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->autoIncrement(true)->end()
    ->field('name', 'string')->end()
    ->field('email', 'string')->end()
    ->table('users')                    // custom table name (optional)
    ->entity(EntityClass::class)        // entity class (optional)
    ->database('default')               // database name (optional)
    ->hidden(false)                     // hide from schema generation
    ->end();
```

### Collection Properties

| Method | Description | Default |
|--------|-------------|---------|
| `name(string)` | Collection name (used as role) | Required |
| `primaryKey(string ...$fields)` | Declare primary key field name(s) | Required for most consumers |
| `table(string)` | Database table name | Same as name |
| `entity(string)` | Entity class name | `stdClass` |
| `database(string)` | Database name | `"default"` |
| `mapper(string)` | Mapper class | `Cycle\ORM\Mapper\StdMapper` |
| `source(string)` | Source class | `ON\ORM\Select\Source` |
| `hidden(bool)` | Hide from schema generation | `false` |

Composite keys:

```php
$registry->collection('user_role')
    ->primaryKey('user_id', 'role_id')
    ->field('user_id', 'int')->end()
    ->field('role_id', 'int')->end()
    ->end();
```

Primitive properties are protected with getters/setters. The `fields` and `relations` maps are public:

```php
$collection->fields;    // FieldMap (public)
$collection->relations; // RelationMap (public)
```

---

## Field Definition

A Field represents a database column. Primary-key membership is declared on the **collection** via `primaryKey(...)`, not on the field.

```php
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')
        ->autoIncrement(true)
        ->end()
    ->field('name', 'string')
        ->maxLength(255)
        ->nullable(false)
        ->default('john')
        ->unique(false)
        ->indexed(false)
        ->type('varchar')
        ->comment("User's full name")
        ->hidden(false)
        ->end();
```

### Field Methods

| Method | Description |
|--------|-------------|
| `autoIncrement(bool)` | Enable auto-increment (also marks Cycle ON_INSERT generated) |
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
| `sensible(bool)` | Mark sensitive data; `true` also hides the field from output |
| `typecast(string\|callable)` | Set typecast handler |
| `validation(string)` | Set validation rules (pipe syntax) |

`isPrimaryKey()` on a field reflects whether that field is listed in the collection's `primaryKey(...)`.

### Field Validation

Fields support validation rules using `somnambulist/validation` pipe syntax:

```php
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->field('name', 'string')->validation('required|max:255')->end()
    ->field('email', 'string')->validation('required|email|max:255')->end()
    ->field('age', 'int')->validation('min:0|max:150')->end()
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

## Field Type Handlers and Cycle Schema

ORM field `->type()` serves two roles:

1. **Mapper resolution** — which `FieldTypeInterface` handler converts values between Storage, PHP, and Wire representations (see [Mapper](mapper.md)).
2. **Cycle schema** — which physical column type `CycleRegistryGenerator` emits when compiling the ORM schema.

### Builtin string types

Most fields use Cycle-compatible string types directly:

```php
->primaryKey('id')
->field('id', 'int')->type('int')->end()
->field('name', 'string')->type('string')->maxLength(128)->end()
->field('created_at', 'datetime')->type('datetime')->end()
->field('payload', 'json')->type('json')->end()
```

`CycleRegistryGenerator` accepts known Cycle column types (`int`, `string`, `datetime`, `text`, `json`, `serial`, `primary`, etc.). Unknown types throw `FieldException` at schema compile time.

For `string` fields without an explicit length (`string(32)`), the generator applies `maxLength()` and emits `string(N)`.

### Generated on insert

A field is marked Cycle `GeneratedField::ON_INSERT` only when `autoIncrement(true)` is set, or when the Cycle type is `primary` / `bigprimary` / `serial` / `bigserial` / `smallserial`. Ordinary primary-key fields (for example `int` without auto-increment) are **not** treated as generated.

### Field type handler classes

You can set `->type()` to a class implementing `ON\Data\Mapper\FieldTypeInterface`. The generator reads `getStorageType()` for the Cycle column type:

```php
use ON\Data\Mapper\Field\DateTimeFieldType;

$registry->collection('event')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->field('starts_at', 'datetime')->type(DateTimeFieldType::class)->end()
    ->end();
// Cycle column type: datetime
```

Handler-resolved `string` still respects `maxLength()`:

```php
use ON\Data\Mapper\Field\StringFieldType;

->field('code', 'string')->type(StringFieldType::class)->maxLength(64)->end()
// Cycle column type: string(64)
```

`getStorageType()` describes **DB encoding only** (`'string'`, `'int'`, `'datetime'`). Register handlers via DataIntegration `DataMapperConfig` / `MapperManager::register()`.

### Enums and custom PHP types

For backed enums and custom PHP classes, keep the ORM `->type()` as a Cycle column type (usually `'string'` or `'int'`) and register a Mapper field type for the PHP class:

```php
// ORM definition
->field('status', 'string')->type('string')->maxLength(32)->end()

// DataMapperConfig (ConfigConfigureEvent)
->register(StatusEnumFieldType::class)
```

Do not use the enum class name as `->type()` unless it is also a `FieldTypeInterface` handler class — otherwise schema compilation fails.

### Cycle schema conversion

`CycleRegistryGenerator` resolves each field type as follows:

1. If `getType()` is a `FieldTypeInterface` class → use `getStorageType()`.
2. If the type contains parameters (`string(32)`, `datetime(6)`) → validate the base type and pass through unchanged.
3. If the type is a known Cycle column name → use it (`string` becomes `string(maxLength)`).
4. Otherwise → throw `FieldException`.

See [Converting to Other Schemas](#converting-to-other-schemas) for running the generator.

---

## Relation Definition

### Key Convention (Cycle ORM)

All relations follow the Cycle ORM key convention:

- **`innerKey`** — the key column on the **source** entity (the one defining the relation)
- **`outerKey`** — the key column on the **target** entity (the related entity)

Use database column names for relation keys. Overnight may resolve field names for compatibility when a field maps to a different column, but column names are the canonical relation definition.

Think of it from the perspective of the entity you're writing the definition on:
- "inner" = my table (the source)
- "outer" = the other table (the target)

| Relation | Source | Target | innerKey (source column) | outerKey (target column) |
|----------|--------|--------|--------------------------|--------------------------|
| User hasMany posts | `user` | `post` | `id` | `user_id` |
| Post belongsTo user | `post` | `user` | `user_id` | `id` |
| User hasOne profile | `user` | `profile` | `id` | `user_id` |
| Post M2M tags | `post` | `tag` | `id` | `id` (via pivot) |

### Convenience Relation Methods

The `Collection` class provides shorthand methods for defining relations without importing relation classes:

```php
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->hasMany('posts', 'post')       // HasManyRelation
    ->hasOne('profile', 'profile')   // HasOneRelation
    ->end();

$registry->collection('post')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->belongsTo('author', 'user')    // BelongsToRelation
    ->end();
```

Each method takes the relation name and target collection name, and returns the relation object for further configuration.

### HasMany

A user has many posts. The FK (`user_id`) lives on the **target** (post), so it's the `outerKey`:

```php
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->hasMany('posts', 'post')
        ->load('eager')           // load strategy: 'lazy' or 'eager'
        ->cascade(true)           // cascade operations
        ->innerKey('id')          // source key: user.id
        ->outerKey('user_id')     // target key: post.user_id
        ->end();
```

### HasOne

A user has one profile. The FK (`user_id`) lives on the **target** (profile), so it's the `outerKey`:

```php
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->hasOne('profile', 'profile')
        ->nullable(true)
        ->cascade(true)
        ->innerKey('id')          // source key: user.id
        ->outerKey('user_id')     // target key: profile.user_id
        ->end();
```

### BelongsTo

A post belongs to a user. The FK (`user_id`) lives on the **source** (post), so it's the `innerKey`:

```php
$registry->collection('post')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->field('user_id', 'int')->end()
    ->belongsTo('author', 'user')
        ->nullable(true)
        ->innerKey('user_id')     // source key: post.user_id
        ->outerKey('id')          // target key: user.id
        ->end();
```

### ManyToMany (M2M)

A post has many tags through a pivot table. The `through()` defines the junction table with its own inner/outer keys:

```php
$registry->collection('post')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->manyToMany('tags', 'tag')
        ->innerKey('id')              // source key: post.id
        ->outerKey('id')              // target key: tag.id
        ->through('post_tags')        // pivot table
            ->innerKey('post_id')     // pivot FK to source: post_tags.post_id
            ->outerKey('tag_id')      // pivot FK to target: post_tags.tag_id
            ->end()
        ->end();
```

### FirstOfMany

`FirstOfManyRelation` is query-only in ON\Data (`persistencePlanner` is `null`). `CycleRegistryGenerator` skips relations without a persistence planner and does not approximate FirstOfMany as `hasMany`. RestApi/query loading still uses first-of-many semantics. See [ON\Data Definition Architecture](ondata-cycle-schema-migration.md#firstofmany).

### Relation Methods

| Method | Description | Default |
|--------|-------------|---------|
| `load(string)` | Load strategy (`lazy` or `eager`) | `"lazy"` |
| `cascade(bool)` | Cascade operations | `true` |
| `nullable(bool)` | Allow nullable relation | `false` |
| `innerKey(string)` | Key column on the **source** entity (the one defining the relation) | Generated |
| `outerKey(string)` | Key column on the **target** entity (the related entity) | Generated |
| `exclusive(bool)` | Exclusive relation (HasOne only) | `false` |
| `getCardinality()` | Returns `'single'` or `'many'` | Varies by type |
| `isJunction()` | Whether this is a M2M pivot relation | `false` |

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
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()                     // returns to Collection
    ->field('name', 'string')->end()                // returns to Collection
    ->hasMany('posts', 'post')                      // returns to Relation
        ->cascade(true)->end()                       // returns to Collection
    ->end();                                         // returns to Registry

// WRONG - missing ->end() leaves you in wrong context
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')  // stuck in Field!
```

### ->end() Return Chain

- `Field.end()` → `Collection`
- `Relation.end()` → `Collection`
- `Collection.end()` → `Registry`

---

## Metadata System

The Metadata system allows storing custom data on Collection, Field, or Relation objects. This is useful for adding framework-specific information (like GraphQL resolvers) without modifying the core definition classes.

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
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->metadata('gql::resolver::findAll', function ($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\User::class)->findAll();
    })->end()
    ->metadata('gql::resolver::findById', function ($args, $container) {
        $orm = $container->get(\Cycle\ORM\ORMInterface::class);
        return $orm->getRepository(\App\Models\User::class)->findByPK($args['id']);
    })->end();

// Set metadata on Field (e.g., type override)
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->field('email', 'string')
        ->metadata('gql::type', 'EmailType!')->end()
        ->metadata('gql::resolver', function ($source, $args, $container) {
            $currentUser = $container->get('current_user');
            return $currentUser->isAdmin() ? $source->email : null;
        })->end()
        ->end();

// Set metadata on Relation
$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->end()
    ->hasMany('posts', 'post')
        ->metadata('gql::resolver', function ($user, $args, $container) {
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

Register definitions from an extension listener (or build a `Registry` manually in tests):

```php
<?php

use ON\Data\Definition\Registry;
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;

// Inside DataDefinitionConfigureEvent listener:
$registry = $event->registry; // or: new Registry() in tests

$registry->collection('user')
    ->primaryKey('id')
    ->field('id', 'int')->autoIncrement(true)->end()
    ->field('name', 'string')->maxLength(255)->validation('required|max:255')->end()
    ->field('email', 'string')->maxLength(255)->unique(true)->validation('required|email|max:255')->end()
    ->field('password', 'string')->hidden(true)->end()
    ->field('created_at', 'datetime')->end()
    ->hasMany('posts', 'post')
        ->innerKey('id')->outerKey('user_id')
        ->cascade(true)->load('lazy')->end()
    ->hasOne('profile', 'profile')
        ->innerKey('id')->outerKey('user_id')
        ->nullable(true)->cascade(true)->end()
    ->end();

$registry->collection('post')
    ->primaryKey('id')
    ->field('id', 'int')->autoIncrement(true)->end()
    ->field('title', 'string')->maxLength(255)->validation('required|max:255')->end()
    ->field('content', 'text')->end()
    ->field('user_id', 'int')->end()
    ->field('created_at', 'datetime')->end()
    ->belongsTo('author', 'user')
        ->innerKey('user_id')->outerKey('id')->end()
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
$schema = (new \Cycle\ORM\Schema\Compiler())->compile($cycleRegistry, [
    new CycleRegistryGenerator($registry),
    // ... other generators
]);

// Convert to GraphQL Schema
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\GraphQL\Resolver\SqlResolver;

$resolver = new SqlResolver($registry, $database);
$graphqlGenerator = new GraphQLRegistryGenerator($registry, $resolver);
$graphqlSchema = $graphqlGenerator->generate();
```

### Adding New Generators

To create a new generator (e.g., for REST, JSON:API), follow this pattern:

```php
use ON\Data\Definition\Registry;
use ON\Data\Definition\Collection\Collection;

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
            $schema[$collection->getName()] = $this->convertCollection($collection);
        }

        return $schema;
    }

    protected function convertCollection(Collection $collection): array
    {
        return [
            'name' => $collection->getName(),
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

- [ON\Data Definition Architecture](ondata-cycle-schema-migration.md)
- [GraphQL Extension Documentation](extensions/graphql.md)
- [Testing Guide](./testing.md)
- [Cycle ORM Documentation](https://cycle-orm.dev/)
- [GraphQL PHP Library](https://webonyx.github.io/graphql-php/)
