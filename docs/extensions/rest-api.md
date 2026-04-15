# REST API Extension

The REST API extension auto-generates RESTful CRUD endpoints from your ORM entity definitions. Inspired by the [Directus API](https://directus.io/docs/api), it provides a complete REST interface with filtering, sorting, field selection, pagination, search, aggregation, and nested relational operations — all from a single extension install.

## Table of Contents

1. [Installation](#installation)
2. [Quick Start](#quick-start)
3. [Configuration](#configuration)
4. [Endpoints](#endpoints)
5. [Query Parameters](#query-parameters)
6. [Filter Operators](#filter-operators)
7. [Aggregation](#aggregation)
8. [Nested Relations](#nested-relations)
9. [Many-to-Many Operations](#many-to-many-operations)
10. [File Uploads](#file-uploads)
11. [Events](#events)
12. [ETag and Conditional Requests](#etag-and-conditional-requests)
13. [Validation](#validation)
14. [Error Handling](#error-handling)
15. [Differences from Directus](#differences-from-directus)

---

## Installation

Install the extension in your application:

```php
use ON\RestApi\RestApiExtension;

RestApiExtension::install($app, [
    'path' => '/items',
]);
```

The extension requires the `container` and `events` extensions.

---

## Quick Start

Once installed, every non-hidden collection in your Registry gets a full set of REST endpoints:

```
GET    /items/user          → List users
GET    /items/user/1        → Get user by ID
POST   /items/user          → Create user
PATCH  /items/user/1        → Update user
DELETE /items/user/1        → Delete user
```

```bash
# List all published posts with author info, sorted by newest first
curl "http://localhost/items/post?filter[status][_eq]=published&sort=-created_at&fields=id,title,author.name"

# Create a new post
curl -X POST http://localhost/items/post \
  -H "Content-Type: application/json" \
  -d '{"title": "Hello World", "content": "My first post", "status": "draft", "user_id": 1}'

# Update a post
curl -X PATCH http://localhost/items/post/1 \
  -H "Content-Type: application/json" \
  -d '{"status": "published"}'

# Delete a post
curl -X DELETE http://localhost/items/post/1
```

---

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `path` | string | `/items` | Base path for REST endpoints |
| `defaultLimit` | int | `100` | Default page size for list queries |
| `maxLimit` | int | `1000` | Maximum allowed page size |
| `rateLimit` | int | `100` | Max requests per window (requires `ratelimit` extension) |
| `rateLimitWindow` | int | `60` | Rate limit window in seconds |
| `resolver` | string | `auto` | `auto`, `sql`, or a fully-qualified class name |
| `addons` | array | `[]` | Optional addons to load (see [Addons](#addons)) |
| `enabled` | bool | `true` | Enable/disable the extension |

```php
RestApiExtension::install($app, [
    'path' => '/api',
    'defaultLimit' => 50,
    'maxLimit' => 500,
    'rateLimit' => 200,
    'rateLimitWindow' => 60,
    'resolver' => 'auto',
]);
```

### Resolver Auto-Detection

When `resolver` is `auto`, the extension detects the database type from `DatabaseManager`:
- `PdoDatabase` → `SqlRestResolver` (raw PDO queries)
- `CycleDatabase` → `CycleRestResolver` (Cycle ORM entities)
- Custom FQCN → resolved from the container

You can also force a specific resolver:

```php
RestApiExtension::install($app, [
    'resolver' => 'sql',    // Always use SqlRestResolver
    'resolver' => 'cycle',  // Always use CycleRestResolver
    'resolver' => MyCustomResolver::class, // Custom implementation
]);
```

### CycleRestResolver

The Cycle resolver uses `ORMInterface`, `EntityManager`, and `RepositoryInterface` from Cycle ORM. It converts entities to associative arrays for the REST response. Key differences from `SqlRestResolver`:

- **Filtering**: Uses Cycle's `findAll($scope)` for simple equality filters. Complex operators (`_contains`, `_between`, etc.) are not supported — use `SqlRestResolver` or a custom resolver for advanced filtering.
- **Search**: Applied in PHP after fetching (no SQL LIKE). Fine for small datasets, not ideal for large tables.
- **Sorting**: Applied in PHP via `usort()`.
- **Aggregation**: Uses Cycle DBAL directly for aggregate SQL queries.
- **M2M**: Uses Cycle DBAL for junction table operations.
- **Transactions**: Managed by Cycle's `EntityManager` (unit of work pattern).

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/items/{collection}` | List items (with query parameters) |
| `GET` | `/items/{collection}/{id}` | Get single item by ID |
| `POST` | `/items/{collection}` | Create item (JSON object) or batch create (JSON array) |
| `PATCH` | `/items/{collection}/{id}` | Update item (partial) |
| `PATCH` | `/items/{collection}` | Batch update (JSON array with PKs) |
| `DELETE` | `/items/{collection}/{id}` | Delete item |
| `DELETE` | `/items/{collection}` | Batch delete (JSON array of IDs) |

### Response Format

All responses use a consistent envelope:

```json
// List response
{
    "data": [
        {"id": 1, "name": "John"},
        {"id": 2, "name": "Jane"}
    ],
    "meta": {
        "total_count": 150,
        "filter_count": 42
    }
}

// Single item response
{
    "data": {"id": 1, "name": "John", "email": "john@test.com"}
}

// Delete response: 204 No Content (empty body)
```

---

## Query Parameters

### fields

Control which fields are returned. Supports dot notation for relations.

```
GET /items/post?fields=id,title,author.name
```

### filter

Directus-style nested filter syntax. See [Filter Operators](#filter-operators).

```
GET /items/post?filter[status][_eq]=published&filter[user_id][_gt]=5
```

### sort

Comma-separated field names. Prefix with `-` for descending.

```
GET /items/post?sort=-created_at,title
```

### limit, offset, page

```
GET /items/post?limit=25&offset=50
GET /items/post?limit=25&page=3       # page 3 = offset 50
```

`limit` is clamped to `maxLimit`. When both `offset` and `page` are provided, `offset` takes precedence.

### search

Full-text search across all string fields using LIKE.

```
GET /items/post?search=graphql
```

Generates: `WHERE (title LIKE '%graphql%' OR content LIKE '%graphql%' OR ...)`

Can be combined with filters (AND logic):

```
GET /items/post?search=graphql&filter[status][_eq]=published
```

### meta

Request metadata about the result set.

```
GET /items/post?meta=total_count,filter_count
```

- `total_count` — total items in the collection (no filters)
- `filter_count` — items matching the current filters

### deep

Apply query parameters to nested relations.

```
GET /items/user?fields=id,name,posts.title&deep[posts][_sort]=-created_at&deep[posts][_limit]=5&deep[posts][_offset]=0
```

---

## Filter Operators

| Operator | SQL | Example |
|----------|-----|---------|
| `_eq` | `= ?` | `filter[status][_eq]=published` |
| `_neq` | `!= ?` | `filter[status][_neq]=draft` |
| `_lt` | `< ?` | `filter[age][_lt]=30` |
| `_lte` | `<= ?` | `filter[age][_lte]=30` |
| `_gt` | `> ?` | `filter[age][_gt]=18` |
| `_gte` | `>= ?` | `filter[age][_gte]=18` |
| `_in` | `IN (?, ...)` | `filter[status][_in]=published,draft` |
| `_nin` | `NOT IN (?, ...)` | `filter[status][_nin]=archived` |
| `_null` | `IS NULL` | `filter[email][_null]=true` |
| `_nnull` | `IS NOT NULL` | `filter[email][_nnull]=true` |
| `_contains` | `LIKE '%val%'` | `filter[name][_contains]=john` |
| `_ncontains` | `NOT LIKE '%val%'` | `filter[name][_ncontains]=test` |
| `_starts_with` | `LIKE 'val%'` | `filter[name][_starts_with]=Jo` |
| `_ends_with` | `LIKE '%val'` | `filter[name][_ends_with]=hn` |
| `_between` | `BETWEEN ? AND ?` | `filter[age][_between]=18,65` |
| `_nbetween` | `NOT BETWEEN ? AND ?` | `filter[age][_nbetween]=0,17` |
| `_empty` | `IS NULL OR = ''` | `filter[bio][_empty]=true` |
| `_nempty` | `IS NOT NULL AND != ''` | `filter[bio][_nempty]=true` |

### Logical Operators

```
# OR: posts where status is published OR draft
GET /items/post?filter[_or][0][status][_eq]=published&filter[_or][1][status][_eq]=draft

# AND: posts where status is published AND user_id is 1
GET /items/post?filter[_and][0][status][_eq]=published&filter[_and][1][user_id][_eq]=1
```

All filter values are parameterized — no SQL injection risk.

---

## Aggregation

Run aggregate functions on the list endpoint by adding `aggregate` and optional `groupBy` parameters.

```
GET /items/order?aggregate[count]=id
GET /items/order?aggregate[sum]=amount&aggregate[avg]=amount
GET /items/order?aggregate[count]=id&groupBy[]=status
GET /items/order?aggregate[count]=id&groupBy[]=status&groupBy[]=country
```

**Supported functions:** `count`, `sum`, `avg`, `min`, `max`

**Response format:**

```json
// GET /items/order?aggregate[count]=id&aggregate[sum]=amount&groupBy[]=status
{
    "data": [
        {"status": "completed", "count": {"id": 42}, "sum": {"amount": 15000}},
        {"status": "pending", "count": {"id": 8}, "sum": {"amount": 2400}}
    ]
}

// Without groupBy — single result
// GET /items/order?aggregate[count]=id
{
    "data": [
        {"count": {"id": 50}}
    ]
}
```

Aggregation can be combined with filters:

```
GET /items/order?aggregate[sum]=amount&groupBy[]=status&filter[created_at][_gte]=2024-01-01
```

---

## Nested Relations

### Create with hasMany

Create a user with posts in a single request:

```bash
curl -X POST http://localhost/items/user \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Alice",
    "email": "alice@test.com",
    "posts": [
        {"title": "First Post", "content": "Hello", "status": "published"},
        {"title": "Second Post", "content": "World", "status": "draft"}
    ]
}'
```

The extension creates the user first, then creates each post with `user_id` set to the new user's ID.

### Create with belongsTo

Create a post with a new author:

```bash
curl -X POST http://localhost/items/post \
  -H "Content-Type: application/json" \
  -d '{
    "title": "New Post",
    "content": "Content here",
    "status": "published",
    "author": {"name": "NewAuthor", "email": "new@test.com"}
}'
```

The extension creates the author first, then creates the post with `user_id` set to the new author's ID.

To assign an existing author, pass the FK directly:

```json
{"title": "New Post", "user_id": 5}
```

### Update with nested upsert

Update a user and upsert their posts (create new ones, update existing ones by PK):

```bash
curl -X PATCH http://localhost/items/user/1 \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Updated Name",
    "posts": [
        {"id": 1, "title": "Updated Title"},
        {"title": "Brand New Post", "content": "New", "status": "draft"}
    ]
}'
```

All nested operations are wrapped in a database transaction. If any part fails, everything rolls back.

---

## Many-to-Many Operations

M2M relations support `connect`, `disconnect`, and `create` operations:

### Connect existing items

```bash
curl -X POST http://localhost/items/post \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Tagged Post",
    "user_id": 1,
    "status": "published",
    "tags": {"connect": [1, 3, 5]}
}'
```

### Disconnect items

```bash
curl -X PATCH http://localhost/items/post/1 \
  -H "Content-Type: application/json" \
  -d '{
    "tags": {"disconnect": [2, 4]}
}'
```

### Create and connect new items

```bash
curl -X PATCH http://localhost/items/post/1 \
  -H "Content-Type: application/json" \
  -d '{
    "tags": {"create": [{"name": "PHP"}, {"name": "REST"}]}
}'
```

### Combined operations

```bash
curl -X PATCH http://localhost/items/post/1 \
  -H "Content-Type: application/json" \
  -d '{
    "tags": {
        "create": [{"name": "NewTag"}],
        "connect": [5],
        "disconnect": [1]
    }
}'
```

Execution order: `create` → `connect` → `disconnect`. All operations are idempotent — connecting an already-connected ID or disconnecting a non-existent link produces no error.

---

## File Uploads

File fields (type `file`, `image`, or `upload`) are handled via `multipart/form-data` requests. The extension dispatches a `FileUpload` event for each file — your application provides the storage logic.

### Request format

```bash
curl -X POST http://localhost/items/post \
  -F 'data={"title":"Post with Image","status":"published","user_id":1}' \
  -F 'cover_image=@/path/to/image.jpg'
```

The `data` part contains the JSON fields. File parts are mapped to their corresponding file-type fields.

### Setting up a file handler

File uploads require an event listener. Without one, the API returns a `400 FILE_HANDLER_MISSING` error.

**Local disk storage:**

```php
use ON\RestApi\Event\FileUpload;

$app->events->eventDispatcher->subscribeTo('restapi.file.upload', function (FileUpload $event) {
    $file = $event->getFile();
    $collection = $event->getCollection()->getName();
    $field = $event->getFieldName();

    $ext = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $path = "uploads/{$collection}/{$name}";

    $file->moveTo("/var/www/storage/{$path}");

    $event->setStoredPath($path);
    $event->preventDefault();
});
```

**S3 storage:**

```php
use ON\RestApi\Event\FileUpload;

$app->events->eventDispatcher->subscribeTo('restapi.file.upload', function (FileUpload $event) use ($s3) {
    $file = $event->getFile();
    $key = 'uploads/' . $event->getCollection()->getName() . '/'
         . uniqid() . '.' . pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);

    $s3->putObject([
        'Bucket' => 'my-bucket',
        'Key' => $key,
        'Body' => $file->getStream(),
        'ContentType' => $file->getClientMediaType(),
    ]);

    $event->setStoredPath("https://my-bucket.s3.amazonaws.com/{$key}");
    $event->preventDefault();
});
```

The stored path is saved in the database column as a string.

---

## Events

The REST API uses a browser-style event model: one event per operation, dispatched before the default action. Listeners can modify input, replace the default action via `preventDefault()`, or react to results.

### Event Reference

| Event Class | Event Name | Preventable | Can Modify | Default Action |
|-------------|-----------|-------------|------------|----------------|
| `ItemCreate` | `restapi.item.create` | Yes | `setInput()` | SQL INSERT |
| `ItemUpdate` | `restapi.item.update` | Yes | `setInput()` | SQL UPDATE |
| `ItemDelete` | `restapi.item.delete` | Yes | — | SQL DELETE |
| `ItemList` | `restapi.item.list` | Yes | — | SQL SELECT |
| `ItemGet` | `restapi.item.get` | Yes | — | SQL SELECT by ID |
| `FileUpload` | `restapi.file.upload` | Yes | `setFile()` | None (requires listener) |
| `RequestComplete` | `restapi.request.complete` | No | — | — |

All event classes are in the `ON\RestApi\Event` namespace.

### How it works

```
1. Dispatch event (listeners run)
2. Check event->isDefaultPrevented()
   → true:  skip default SQL action, use event->getResult()
   → false: run default SQL action, set result on event
3. Return result
```

### Example: External search with Meilisearch

Replace the default LIKE search with Meilisearch for better performance on large datasets:

```php
use ON\RestApi\Event\ItemList;

$app->events->eventDispatcher->subscribeTo('restapi.item.list', function (ItemList $event) use ($meili) {
    $search = $event->getParams()['search'] ?? null;
    if ($search === null) {
        return; // No search term — let default LIKE run
    }

    $index = $event->getCollection()->getName();
    $result = $meili->index($index)->search($search, [
        'limit' => $event->getParams()['limit'] ?? 100,
        'offset' => $event->getParams()['offset'] ?? 0,
    ]);

    $event->setResult($result->getHits(), $result->getEstimatedTotalHits());
    $event->preventDefault();
});
```

### Example: Soft delete

Replace hard delete with a soft delete (set `deleted_at` instead of removing the row):

```php
use ON\RestApi\Event\ItemDelete;

$app->events->eventDispatcher->subscribeTo('restapi.item.delete', function (ItemDelete $event) use ($db) {
    $collection = $event->getCollection();
    $table = $collection->getTable();
    $id = $event->getId();

    $stmt = $db->getConnection()->prepare(
        "UPDATE `{$table}` SET `deleted_at` = datetime('now') WHERE `id` = ?"
    );
    $stmt->execute([$id]);

    $event->setResult(['id' => $id, 'deleted_at' => date('Y-m-d H:i:s')]);
    $event->preventDefault();
});
```

### Example: Auto-set timestamps on create

```php
use ON\RestApi\Event\ItemCreate;

$app->events->eventDispatcher->subscribeTo('restapi.item.create', function (ItemCreate $event) {
    $input = $event->getInput();
    $input['created_at'] = date('Y-m-d H:i:s');
    $input['updated_at'] = date('Y-m-d H:i:s');
    $event->setInput($input);
});
```

### Example: Audit logging on update

```php
use ON\RestApi\Event\ItemUpdate;

$app->events->eventDispatcher->subscribeTo('restapi.item.update', function (ItemUpdate $event) use ($logger) {
    $logger->info('Item updated', [
        'collection' => $event->getCollection()->getName(),
        'id' => $event->getId(),
        'fields' => array_keys($event->getInput()),
    ]);
});
```

### Using EventSubscriberInterface

You can also register events via `EventSubscriberInterface`:

```php
use ON\Event\EventSubscriberInterface;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemUpdate;

class TimestampSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'restapi.item.create' => [0, 'onItemCreate'],
            'restapi.item.update' => [0, 'onItemUpdate'],
        ];
    }

    public function onItemCreate(ItemCreate $event): void
    {
        $input = $event->getInput();
        $input['created_at'] = date('Y-m-d H:i:s');
        $event->setInput($input);
    }

    public function onItemUpdate(ItemUpdate $event): void
    {
        $input = $event->getInput();
        $input['updated_at'] = date('Y-m-d H:i:s');
        $event->setInput($input);
    }
}
```

---

## ETag and Conditional Requests

The REST API supports ETags for cache validation and optimistic concurrency control.

### Cache validation (GET)

Every GET response includes a weak `ETag` header:

```
ETag: W/"a1b2c3d4e5f6..."
Cache-Control: no-cache
```

Send `If-None-Match` to check if data has changed:

```bash
curl -H 'If-None-Match: W/"a1b2c3d4e5f6..."' http://localhost/items/user
# → 304 Not Modified (if unchanged)
# → 200 OK with new ETag (if changed)
```

### Optimistic concurrency (PATCH/DELETE)

Send `If-Match` to prevent lost updates:

```bash
# First, GET the item and note the ETag
curl -i http://localhost/items/user/1
# ETag: W/"abc123..."

# Then update with If-Match
curl -X PATCH http://localhost/items/user/1 \
  -H 'If-Match: W/"abc123..."' \
  -H 'Content-Type: application/json' \
  -d '{"name": "Updated"}'
# → 200 OK (if ETag matches)
# → 412 Precondition Failed (if someone else modified it)
```

---

## Validation

Input is validated against the `validation()` rules defined on each field:

```php
$registry->collection('user')
    ->field('name', 'string')->validation('required|max:255')->end()
    ->field('email', 'string')->validation('required|email|max:255')->end()
    ->field('age', 'int')->validation('min:0|max:150')->end()
    ->end();
```

Validation uses [somnambulist/validation](https://github.com/somnambulist-tech/validation) with pipe syntax. On failure, the API returns a `400` with detailed errors:

```json
{
    "errors": [{
        "message": "The name must be at least 3 characters.",
        "extensions": {
            "code": "VALIDATION_ERROR",
            "field": "name",
            "validationErrors": {
                "name": ["The name must be at least 3 characters."],
                "email": ["The email is not a valid email address."]
            }
        }
    }]
}
```

For PATCH requests, only fields present in the request body are validated (partial update).

---

## Error Handling

All errors follow a consistent format:

```json
{
    "errors": [{
        "message": "Human-readable message",
        "extensions": {
            "code": "ERROR_CODE",
            "field": "optional_field_name"
        }
    }]
}
```

### Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `NOT_FOUND` | 404 | Item not found |
| `COLLECTION_NOT_FOUND` | 404 | Collection doesn't exist or is hidden |
| `METHOD_NOT_ALLOWED` | 405 | Unsupported HTTP method |
| `INVALID_JSON` | 400 | Malformed JSON body |
| `VALIDATION_ERROR` | 400 | Input validation failed |
| `DUPLICATE` | 409 | Unique constraint violation |
| `REQUIRED_FIELD` | 400 | NOT NULL constraint violation |
| `FOREIGN_KEY_VIOLATION` | 400 | Foreign key constraint violation |
| `FILE_HANDLER_MISSING` | 400 | File upload with no handler configured |
| `PRECONDITION_FAILED` | 412 | ETag mismatch on PATCH/DELETE |
| `SERVICE_UNAVAILABLE` | 503 | No database resolver configured |
| `INTERNAL_ERROR` | 500 | Unexpected server error |

In debug mode, `INTERNAL_ERROR` responses include a `trace` key in extensions.

---

## Addons

Addons are optional features that plug into the REST API extension via config. They register event listeners and/or provide additional middleware.

```php
use ON\RestApi\Addon\RevisionAddon;
use ON\RestApi\Addon\SchemaAddon;

RestApiExtension::install($app, [
    'addons' => [
        RevisionAddon::class,                              // no options
        SchemaAddon::class,                                // no options
        RevisionAddon::class => ['table' => 'activity'],   // with options
    ],
]);
```

### RevisionAddon

Tracks every create, update, and delete operation in a revisions table. Each revision stores the collection name, item ID, action, a JSON snapshot of the item before the change, and a JSON delta of the changed fields.

**Options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `table` | string | `revisions` | Table name for storing revisions |
| `collections` | array\|null | `null` | Collections to track (`null` = all) |

**Required table schema:**

```sql
CREATE TABLE revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection VARCHAR(255) NOT NULL,
    item_id VARCHAR(255) NOT NULL,
    action VARCHAR(20) NOT NULL,   -- 'create', 'update', 'delete'
    data TEXT,                      -- JSON snapshot before change
    delta TEXT,                     -- JSON of changed fields
    created_at DATETIME NOT NULL
);
```

**Example:**

```php
RestApiExtension::install($app, [
    'addons' => [
        RevisionAddon::class => [
            'table' => 'activity',
            'collections' => ['post', 'user'],  // only track these
        ],
    ],
]);
```

### SchemaAddon

Exposes collection schema as read-only REST endpoints. Useful for building dynamic admin UIs that adapt to the schema.

**Endpoints:**

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/items/_schema` | List all non-hidden collections with their fields |
| `GET` | `/items/_schema/{collection}` | Get fields and relations for a specific collection |

**Response example:**

```json
// GET /items/_schema/post
{
    "data": {
        "name": "post",
        "table": "post",
        "description": "Blog posts",
        "fields": [
            {"name": "id", "type": "int", "primaryKey": true, "nullable": false, "hidden": false},
            {"name": "title", "type": "string", "primaryKey": false, "nullable": true, "hidden": false, "validation": "required|max:255"},
            {"name": "user_id", "type": "int", "primaryKey": false, "nullable": false, "hidden": false}
        ],
        "relations": [
            {"name": "comments", "collection": "comment", "cardinality": "many", "junction": false, "innerKey": "id", "outerKey": "post_id"},
            {"name": "author", "collection": "user", "cardinality": "single", "junction": false, "innerKey": "user_id", "outerKey": "id"}
        ]
    }
}
```

### Creating Custom Addons

Implement `RestApiAddonInterface`:

```php
use ON\RestApi\Addon\RestApiAddonInterface;

class MyAddon implements RestApiAddonInterface
{
    public function register(
        Registry $registry,
        ?RestResolverInterface $resolver,
        ?EventDispatcherInterface $eventDispatcher,
        ?\PDO $connection,
        array $options = []
    ): void {
        // Register event listeners, set up state, etc.
        $eventDispatcher?->subscribeTo('restapi.item.create', function (ItemCreate $event) {
            // Custom logic
        });
    }

    public function getMiddleware(): ?MiddlewareInterface
    {
        return null; // or return a PSR-15 middleware
    }
}
```

---

## Differences from Directus

| Feature | Overnight REST API | Directus |
|---------|-------------------|----------|
| File storage | Event-driven (you provide the handler) | Built-in storage adapters (S3, local, etc.) |
| Authentication | Separate `auth` extension | Built-in auth with tokens/SSO |
| Permissions | Via event listeners or auth middleware | Built-in role-based access control |
| System collections | None | `directus_*` system tables |
| Webhooks | Via event listeners | Built-in webhook system |
| `countDistinct`, `sumDistinct`, `avgDistinct` | Not yet supported | Supported |
| Revision history | Not built-in | Built-in |
| Search | LIKE-based (external via events) | Built-in + external search support |
