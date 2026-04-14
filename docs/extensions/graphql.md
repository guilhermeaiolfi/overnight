# GraphQL Extension

The GraphQL extension provides GraphQL API support for the Overnight framework. It automatically generates a full GraphQL schema — types, queries, and mutations — from your ORM entity definitions. No boilerplate resolvers needed.

## Table of Contents

1. [Installation](#installation)
2. [Quick Start](#quick-start)
3. [Resolver Architecture](#resolver-architecture)
4. [Queries](#queries)
5. [Mutations](#mutations)
6. [File Uploads](#file-uploads)
7. [Mutation Events](#mutation-events)
8. [Enum Fields](#enum-fields)
9. [Validation](#validation)
9. [Error Handling](#error-handling)
10. [Schema Caching](#schema-caching)
11. [Custom Resolvers](#custom-resolvers)
12. [Configuration](#configuration)
13. [API Reference](#api-reference)

---

## Installation

The GraphQL extension requires `webonyx/graphql-php`. Add it to your dependencies:

```bash
composer require webonyx/graphql-php
```

Then install the extension in your application:

```php
// config/extensions.php

use ON\GraphQL\GraphQLExtension;

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
    ->field("email", "string")->validation('required|email|max:255')->end()
    ->hasMany("posts", "post")
        ->end()
    ->end();

$registry->collection("post")
    ->field("id", "int")->primaryKey(true)->end()
    ->field("title", "string")->validation('required|max:255')->end()
    ->field("content", "text")->end()
    ->field("user_id", "int")->end()
    ->belongsTo("author", "user")
        ->innerKey('user_id')->outerKey('id')->end()
        ->end()
    ->end();
```

### Step 2: Choose a Resolver

Pass a resolver to the generator. Two built-in resolvers are available:

```php
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\GraphQL\Resolver\SqlResolver;

// Option A: SqlResolver — raw PDO/SQL queries
$resolver = new SqlResolver($registry, $database);
$generator = new GraphQLRegistryGenerator($registry, $container, $resolver);
$schema = $generator->generate();
```

```php
use ON\GraphQL\GraphQLRegistryGenerator;
use ON\GraphQL\Resolver\CycleResolver;

// Option B: CycleResolver — uses Cycle ORM's repository pattern
$resolver = new CycleResolver($orm, $registry);
$generator = new GraphQLRegistryGenerator($registry, $container, $resolver);
$schema = $generator->generate();
```

### Step 3: Query Your API

All non-hidden collections automatically get queries and mutations.

```graphql
# List users with pagination
{
  user(limit: 10, offset: 0) {
    items {
      id
      name
      email
      posts {
        id
        title
      }
    }
    totalCount
  }
}

# Get a single user by ID
{
  user_by_id(id: "1") {
    id
    name
  }
}
```

---

## Resolver Architecture

The generator accepts an optional `GraphQLResolverInterface` that handles all data operations. You don't need to write per-collection resolvers — the built-in resolvers handle everything.

### Constructor

```php
new GraphQLRegistryGenerator(
    Registry $ormRegistry,
    ?ContainerInterface $container = null,
    ?GraphQLResolverInterface $resolver = null
);
```

### Built-in Resolvers

| Resolver | Backend | Use Case |
|----------|---------|----------|
| `SqlResolver` | Raw PDO/SQL | Simple apps, no ORM dependency |
| `CycleResolver` | Cycle ORM repositories | Apps using Cycle ORM |

### SqlResolver

Uses raw SQL via `DatabaseInterface`. Handles filtering, sorting, pagination, nested creates, and relation batching via the built-in DataLoader.

```php
use ON\GraphQL\Resolver\SqlResolver;

$resolver = new SqlResolver($registry, $database);
```

### CycleResolver

Uses Cycle ORM's `EntityManager` and repository pattern. Supports eager-loaded relations and entity class instantiation.

```php
use ON\GraphQL\Resolver\CycleResolver;

$resolver = new CycleResolver($orm, $registry);
```

### Custom Resolver

Implement `GraphQLResolverInterface` for full control:

```php
use ON\GraphQL\Resolver\GraphQLResolverInterface;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Relation\RelationInterface;

class MyResolver implements GraphQLResolverInterface
{
    public function resolveCollection(Collection $collection, array $args = []): array;
    public function resolveById(Collection $collection, string $id): ?object;
    public function resolveCreate(Collection $collection, array $input): ?object;
    public function resolveUpdate(Collection $collection, string $id, array $input): ?object;
    public function resolveDelete(Collection $collection, string $id): bool;
    public function resolveNestedCreate(Collection $collection, array $input, array $nestedInput): ?object;
    public function resolveRelation(mixed $source, RelationInterface $relation): mixed;
}
```

---

## Queries

### List Queries (Connection Types)

List queries return a connection type with `items` and `totalCount`:

```graphql
{
  post(limit: 20, offset: 0, sort: "title", order: "ASC") {
    items {
      id
      title
      content
    }
    totalCount
  }
}
```

Response:

```json
{
  "data": {
    "post": {
      "items": [
        { "id": "1", "title": "Hello World", "content": "..." }
      ],
      "totalCount": 42
    }
  }
}
```

### Pagination

All list queries accept `limit` and `offset` arguments:

| Argument | Type | Description |
|----------|------|-------------|
| `limit` | `Int` | Maximum number of items to return |
| `offset` | `Int` | Number of items to skip |

### Sorting

| Argument | Type | Description |
|----------|------|-------------|
| `sort` | `String` | Field name to sort by |
| `order` | `String` | `ASC` or `DESC` (default: `ASC`) |

The sort field must exist on the collection. If the field doesn't exist, the sort is silently ignored.

### Filtering

Filterable fields are exposed as query arguments. Pass a value to filter by exact match:

```graphql
{
  user(name: "Alice") {
    items { id name }
    totalCount
  }
}
```

### LIKE Filtering

String filter values containing `%` automatically use `LIKE` instead of `=`:

```graphql
{
  user(name: "%ali%") {
    items { id name }
    totalCount
  }
}
```

### Find By ID

Each collection gets a `<name>_by_id` query:

```graphql
{
  user_by_id(id: "1") {
    id
    name
    email
  }
}
```

---

## Mutations

All non-hidden collections automatically get `create`, `update`, and `delete` mutations. No custom resolvers needed.

### Create

Create mutations use a `FooInput` input type. Required fields from the collection definition are preserved as non-nullable:

```graphql
mutation {
  create_user(input: { name: "Alice", email: "alice@example.com" }) {
    id
    name
    email
  }
}
```

### Update

Update mutations use a `FooUpdateInput` input type where all fields are nullable (you only send what changed):

```graphql
mutation {
  update_user(id: "1", input: { name: "Alice Updated" }) {
    id
    name
  }
}
```

### Delete

```graphql
mutation {
  delete_user(id: "1")
}
```

Returns `true` if the record was deleted, `false` if not found.

### Nested Mutations

Create mutations accept nested relation data. The foreign key is set automatically:

```graphql
mutation {
  create_user(input: {
    name: "Alice",
    email: "alice@example.com",
    posts: [
      { title: "First Post", content: "Hello!" },
      { title: "Second Post", content: "World!" }
    ]
  }) {
    id
    name
    posts {
      id
      title
    }
  }
}
```

Nested input types (`FooNestedInput`) have all fields nullable since the parent sets the foreign key.

### Input Types Summary

| Type | Used By | Field Nullability |
|------|---------|-------------------|
| `FooInput` | `create_foo` | Preserves required/nullable from definition |
| `FooUpdateInput` | `update_foo` | All fields nullable |
| `FooNestedInput` | Nested creates | All fields nullable |

---

## File Uploads

The GraphQL extension supports file uploads via the [GraphQL multipart request specification](https://github.com/jaydenseric/graphql-multipart-request-spec).

### Defining File Fields

Use the `file`, `image`, or `upload` type on a field. These map to the custom `Upload` scalar:

```php
$registry->collection('document')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('name', 'string')->end()
    ->field('attachment', 'file')->end()
    ->end();

$registry->collection('user')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('name', 'string')->end()
    ->field('avatar', 'image')->end()
    ->end();
```

### Client Request

File uploads use `multipart/form-data` with three parts:

- `operations` — the GraphQL query/mutation as JSON, with `null` placeholders for file variables
- `map` — maps file keys to their variable paths
- File parts — the actual binary files

```
POST /graphql
Content-Type: multipart/form-data

operations: {
  "query": "mutation($file: Upload!) { create_document(input: { name: \"Report\", attachment: $file }) { id name } }",
  "variables": { "file": null }
}
map: { "0": ["variables.file"] }
0: (binary file data)
```

### JavaScript Example

```javascript
const formData = new FormData();

formData.append('operations', JSON.stringify({
  query: `mutation($file: Upload!) {
    create_document(input: { name: "Report", attachment: $file }) {
      id
      name
    }
  }`,
  variables: { file: null }
}));

formData.append('map', JSON.stringify({ '0': ['variables.file'] }));
formData.append('0', fileInput.files[0]);

fetch('/graphql', { method: 'POST', body: formData });
```

### Multiple Files

```
operations: {
  "query": "mutation($avatar: Upload!, $cover: Upload!) { ... }",
  "variables": { "avatar": null, "cover": null }
}
map: { "0": ["variables.avatar"], "1": ["variables.cover"] }
0: (avatar file)
1: (cover file)
```

### Handling Uploads

File uploads are best handled via mutation events. See [Mutation Events](#mutation-events) for the recommended approach.

For simple cases, you can also handle files in a custom metadata resolver:

```php
$registry->collection('document')
    ->metadata('gql::resolver::create', function($args, $context) use ($container) {
        $input = $args['input'];
        $file = $input['attachment']; // UploadedFileInterface

        $path = 'uploads/' . $file->getClientFilename();
        $file->moveTo($path);
        $input['attachment'] = $path;

        $repo = $container->get(DocumentRepository::class);
        return $repo->create($input);
    })
    ->end();
```

The file arrives as a PSR-7 `UploadedFileInterface` with methods like `getClientFilename()`, `getSize()`, `getClientMediaType()`, and `moveTo()`.

### Upload Scalar

The `Upload` scalar is input-only — it cannot be serialized in query responses. In output types, file fields return their stored value (typically a path or URL string).

---

## Mutation Events

The GraphQL extension dispatches events before and after each mutation, allowing modules to hook into the mutation lifecycle.

### Event Types

| Event | When | Can Modify Input |
|-------|------|-----------------|
| `BeforeMutation` (`graphql.mutation.before`) | Before resolver executes | Yes — call `$event->setInput(...)` |
| `AfterMutation` (`graphql.mutation.after`) | After resolver executes | No — read-only |

### BeforeMutation

Dispatched before create, update, and delete operations. Listeners can inspect and modify the input data.

```php
use ON\GraphQL\Event\BeforeMutation;

$events->registerListener('graphql.mutation.before', function (BeforeMutation $event) {
    $collection = $event->getCollection();
    $operation = $event->getOperation(); // 'create', 'update', or 'delete'
    $input = $event->getInput();

    // Modify input before it reaches the resolver
    $input['updated_at'] = date('Y-m-d H:i:s');
    $event->setInput($input);
});
```

### AfterMutation

Dispatched after the resolver completes. Useful for logging, cache invalidation, or notifications.

```php
use ON\GraphQL\Event\AfterMutation;

$events->registerListener('graphql.mutation.after', function (AfterMutation $event) {
    $collection = $event->getCollection();
    $operation = $event->getOperation();
    $result = $event->getResult();

    // Log the mutation
    logger()->info("GraphQL {$operation} on {$collection->getName()}", [
        'result_id' => $result->id ?? null,
    ]);
});
```

### File Upload Handling via Events

Instead of handling file uploads in custom resolvers, use a `BeforeMutation` listener to process uploaded files before they reach the database:

```php
use ON\GraphQL\Event\BeforeMutation;
use Psr\Http\Message\UploadedFileInterface;

$events->registerListener('graphql.mutation.before', function (BeforeMutation $event) {
    $input = $event->getInput();
    $collection = $event->getCollection();
    $modified = false;

    foreach ($input as $key => $value) {
        if ($value instanceof UploadedFileInterface) {
            // Save the file and replace with the stored path
            $filename = uniqid() . '_' . $value->getClientFilename();
            $path = "uploads/{$collection->getName()}/{$filename}";
            $value->moveTo($path);

            $input[$key] = $path;
            $modified = true;
        }
    }

    if ($modified) {
        $event->setInput($input);
    }
});
```

This approach keeps file handling out of the GraphQL extension and lets each module decide how to process its files.

---

## Enum Fields

Fields with a fixed set of allowed values can use the `enum` metadata to auto-generate a GraphQL `EnumType`:

```php
$registry->collection('post')
    ->field('status', 'string')
        ->metadata('enum', ['draft', 'published', 'archived'])
        ->end()
    ->end();
```

This generates a `StatusEnum` type in the schema:

```graphql
enum StatusEnum {
  DRAFT
  PUBLISHED
  ARCHIVED
}
```

Enum values are uppercased with spaces/hyphens converted to underscores. The underlying values remain as defined (`'draft'`, `'published'`, etc.).

Enum fields work in both queries (filtering) and mutations (input validation by GraphQL itself):

```graphql
# Query with enum filter
{ post(status: PUBLISHED) { items { id title status } totalCount } }

# Create with enum value
mutation { create_post(input: { title: "New Post", status: DRAFT }) { id status } }
```

---

## Validation

Fields can have validation rules using `somnambulist/validation` pipe syntax:

```php
$registry->collection("user")
    ->field("name", "string")->validation('required|max:255')->end()
    ->field("email", "string")->validation('required|email|max:255')->end()
    ->field("age", "int")->validation('min:0|max:150')->end()
    ->end();
```

Validation runs on create and update mutations. All errors are returned at once in the response extensions:

```json
{
  "errors": [
    {
      "message": "The Email is not valid email",
      "extensions": {
        "code": "VALIDATION_ERROR",
        "field": "email",
        "validationErrors": {
          "email": ["The Email is not valid email"],
          "name": ["The Name is required"]
        }
      }
    }
  ]
}
```

### Available Validation Rules

Uses `somnambulist/validation` — common rules include:

| Rule | Example |
|------|---------|
| `required` | Field must be present and non-empty |
| `email` | Must be a valid email |
| `max:N` | Maximum length (string) or value (number) |
| `min:N` | Minimum length or value |
| `numeric` | Must be numeric |
| `url` | Must be a valid URL |
| `alpha` | Alphabetic characters only |
| `alpha_num` | Alphanumeric characters only |

---

## Error Handling

The extension uses `GraphQLUserError` for structured, client-safe errors with extensions.

### Error Structure

```json
{
  "errors": [
    {
      "message": "A record with this email already exists.",
      "extensions": {
        "code": "DUPLICATE",
        "field": "email"
      }
    }
  ]
}
```

### Error Codes

| Code | Meaning |
|------|---------|
| `VALIDATION_ERROR` | Input validation failed (includes `validationErrors` map) |
| `DUPLICATE` | Unique constraint violation |
| `REQUIRED_FIELD` | NOT NULL constraint violation |
| `FOREIGN_KEY_VIOLATION` | Referenced record does not exist |
| `DATABASE_ERROR` | General database error |
| `INTERNAL_ERROR` | Unexpected server error |

Database errors from PDO exceptions are automatically detected and converted to the appropriate error code.

### GraphQLUserError

```php
use ON\GraphQL\Error\GraphQLUserError;

// Simple error
throw new GraphQLUserError('Something went wrong', 'CUSTOM_CODE', 'fieldName');

// Validation error with all field errors
throw GraphQLUserError::validationFailed([
    'email' => ['The Email is not valid email'],
    'name' => ['The Name is required'],
]);
```

---

## Schema Caching

Use `CachedGraphQLRegistryGenerator` to memoize the generated schema. It detects registry changes via a hash of collection names, fields, and relations:

```php
use ON\GraphQL\CachedGraphQLRegistryGenerator;

$generator = new CachedGraphQLRegistryGenerator($registry, $container, $resolver);

// First call generates the schema
$schema = $generator->generate();

// Subsequent calls return the cached schema (if registry hasn't changed)
$schema = $generator->generate();

// Force regeneration
$generator->invalidate();

// Check cache status
$generator->isCached(); // true/false
```

The cache is invalidated automatically when the registry hash changes (e.g., collections added/removed, fields changed).

---

## Custom Resolvers

While the built-in resolvers handle most cases, you can override individual collection resolvers via metadata:

### Collection-Level Overrides

```php
$registry->collection("user")
    ->metadata('gql::resolver::findAll', function($args, $container) {
        // Custom list logic
        $repo = $container->get(UserRepository::class);
        return [
            'items' => $repo->search($args),
            'totalCount' => $repo->count($args),
        ];
    })
    ->metadata('gql::resolver::findById', function($args, $container) {
        return $container->get(UserRepository::class)->find($args['id']);
    })
    ->end();
```

### Field-Level Overrides

```php
$registry->collection("user")
    ->field("email", "string")
        ->metadata('gql::resolver', function($source, $args, $container) {
            // Only show email to admins
            $currentUser = $container->get('current_user');
            return $currentUser->isAdmin() ? $source->email : null;
        })
        ->end()
    ->end();
```

### Relation-Level Overrides

```php
$registry->collection("user")
    ->hasMany("posts", "post")
        ->metadata('gql::resolver', function($user, $args, $container) {
            // Custom relation loading
            return $container->get(PostRepository::class)
                ->findByUser($user->id);
        })
        ->end()
    ->end();
```

### Field Type Overrides

Override the inferred GraphQL type:

```php
$registry->collection("user")
    ->field("email", "string")
        ->metadata('gql::type', 'String!')
        ->end()
    ->end();
```

### Metadata Keys Reference

| Key | Target | Description |
|-----|--------|-------------|
| `gql::resolver::findAll` | Collection | Override list query resolver |
| `gql::resolver::findById` | Collection | Override find-by-ID resolver |
| `gql::resolver::create` | Collection | Override create mutation resolver |
| `gql::resolver::update` | Collection | Override update mutation resolver |
| `gql::resolver::delete` | Collection | Override delete mutation resolver |
| `gql::resolver` | Field/Relation | Override field or relation resolver |
| `gql::type` | Field | Override inferred GraphQL type |
| `enum` | Field | Array of allowed values — generates EnumType |

---

## Configuration

### Extension Options

```php
$app->install(\ON\GraphQL\GraphQLExtension::class, [
    'path' => '/graphql',     // GraphQL endpoint path (default: '/graphql')
    'enabled' => true,        // Enable/disable extension
]);
```

### Middleware

The `GraphQLMiddleware` handles both GET and POST requests:

- `GET` requests: `?query=...&variables=...&operationName=...`
- `POST` requests: `{"query": "...", "variables": {...}, "operationName": "..."}`

Responses are returned as `JsonResponse`. In debug mode, error messages include debug info and stack traces.

```php
use ON\GraphQL\Middleware\GraphQLMiddleware;

$middleware = new GraphQLMiddleware($schema, debug: true);
```

---

## API Reference

### GraphQLRegistryGenerator

```php
use ON\GraphQL\GraphQLRegistryGenerator;

$generator = new GraphQLRegistryGenerator($registry, $container, $resolver);
$schema = $generator->generate();
```

### CachedGraphQLRegistryGenerator

```php
use ON\GraphQL\CachedGraphQLRegistryGenerator;

$generator = new CachedGraphQLRegistryGenerator($registry, $container, $resolver);
$schema = $generator->generate();
$generator->invalidate();
$generator->isCached();
```

### GraphQLSchemaFactory

Used internally by the extension to create the schema from the container:

```php
use ON\GraphQL\GraphQLSchemaFactory;

$factory = new GraphQLSchemaFactory($container);
$schema = $factory->create($config);
```

### GraphQLResolverInterface

```php
interface GraphQLResolverInterface
{
    public function resolveCollection(Collection $collection, array $args = []): array;
    public function resolveById(Collection $collection, string $id): ?object;
    public function resolveCreate(Collection $collection, array $input): ?object;
    public function resolveUpdate(Collection $collection, string $id, array $input): ?object;
    public function resolveDelete(Collection $collection, string $id): bool;
    public function resolveNestedCreate(Collection $collection, array $input, array $nestedInput): ?object;
    public function resolveRelation(mixed $source, RelationInterface $relation): mixed;
}
```

---

## See Also

- [ORM Entity Definition](../orm-entity-definition.md) - How to define entities
- [DataLoader](graphql-dataloader.md) - Solving the N+1 problem
- [GraphQL PHP Library](https://webonyx.github.io/graphql-php/) - Reference documentation
- [GraphQL Specification](https://graphql.org/) - Official GraphQL spec
