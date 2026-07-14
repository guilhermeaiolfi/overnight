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
8. [Query Functions](#query-functions)
9. [Dynamic Variables](#dynamic-variables)
10. [Nested Relations](#nested-relations)
11. [Many-to-Many Operations](#many-to-many-operations)
12. [File Uploads](#file-uploads)
13. [Events](#events)
14. [ETag and Conditional Requests](#etag-and-conditional-requests)
15. [Validation](#validation)
16. [Error Handling](#error-handling)
17. [Differences from Directus](#differences-from-directus)

---

## Installation

Install the extension in your application:

```php
use ON\RestApi\RestApiExtension;

RestApiExtension::install($app, [
    'path' => '/items',
]);
```

The extension requires the `container`, `events`, and `data` extensions. RestApi serializes and deserializes collection rows through `ON\Data\Mapper` (`ConversionGateway`, `map()->args($collection)->…->to([])`). `DataExtension` registers the default conversion gateway.

```php
use ON\RestApi\RestApiExtension;

RestApiExtension::install($app, ['path' => '/items']);
```

Register custom field handlers on `DataMapperConfig` during `ConfigConfigureEvent` when your entities use non-builtin types (enums, files, etc.).

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
| `addons` | array | `[]` | Optional addons to load (see [Addons](#addons)) |
| `dynamicVariables` | array | `[]` | Values or callables for variables such as `$current_user` in filters |
| `enabled` | bool | `true` | Enable/disable the extension |

```php
RestApiExtension::install($app, [
    'path' => '/api',
    'defaultLimit' => 50,
    'maxLimit' => 500,
    'rateLimit' => 200,
    'rateLimitWindow' => 60,
]);
```

### SQL Resolver and mutation pipeline

**Reads** use ON\Data `SelectQuery` through Directus list/get actions.

**Writes** go through `MutationCoordinator`:

1. **Parse** — `DirectusPayloadParser` → `ToOneMutation` / `ToManyImplicitMutation` / `ToManyExplicitMutation` (rejects duplicate related identities).
2. **Bind** — `DirectusMutationBinder` attaches intent to an ON\Data `Session`, verifying related identities exist and scoping explicit update/delete to the current relation baseline.
3. **Before-events** — parent then children; `preventDefault()` stops flush (no after-events).
4. **Flush** — `Session::sync()` + `Session::flush()` (one transaction for batch endpoints).
5. **After-events** — children then parent; skipped on rollback or prevention.
6. **Reload** — result rows via ON\Data query.

#### Implicit vs explicit to-many

```json
{ "children": [1, 3] }
```

Implicit list = **final membership**. Listed existing items may be retained or newly assigned (when authorized). Omitted baseline members are **unlinked**, not deleted — unless the relation is marked `exclusive(true)`, in which case omitted members are **deleted** (Directus-style `one_deselect_action: delete`).

```json
{
  "children": {
    "update": [{ "id": 3, "name": "Changed" }],
    "delete": [2]
  }
}
```

Explicit `update` / `delete` identities **must already belong** to the relation being mutated. Out-of-scope identities fail with `INVALID_RELATION_TARGET` before persistence.

Missing related identities fail with `RELATED_NOT_FOUND` (payload path included). Duplicate identities in one relation payload fail with `DUPLICATE_RELATED_IDENTITY`.

Internal architecture: [`src/RestApi/README.md`](../../src/RestApi/README.md).  
Upgrade notes for removed relation events: [`rest-api-mutation-upgrade.md`](./rest-api-mutation-upgrade.md).

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
// List response (meta omitted unless requested)
{
    "data": [
        {"id": 1, "name": "John"},
        {"id": 2, "name": "Jane"}
    ]
}

// List response with meta requested
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

Control which public fields are returned. Supports dot notation for relations.

```
GET /items/post?fields=id,title,author.name
```

REST query parameters and JSON payloads use ORM field names, not database column names. SQL resolvers translate field names to columns internally and translate result rows back to field names before returning JSON.

```php
$registry->collection('post')
    ->field('authorId', 'int')->column('author_id')->end()
    ->belongsTo('author', 'user')->innerKey('author_id')->outerKey('id')->end()
    ->end();
```

```
GET /items/post?fields=id,title,authorId
```

The SQL query selects `author_id`, but the response contains `authorId`. Asking for `fields=author_id` is invalid unless the ORM field itself is named `author_id`.

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

Request metadata about the result set. The `meta` key is omitted from the response when this parameter is not provided.

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

Nested relation loads also support `_filter`, which is especially useful with aliases:

```
GET /items/user?fields=id,name,published_posts.title,draft_posts.title&alias[published_posts]=posts&alias[draft_posts]=posts&deep[published_posts][_filter][status][_eq]=published&deep[draft_posts][_filter][status][_eq]=draft
```

### alias

Aliases let a response include the same top-level relation more than once with different `deep` filters, sorting, limits, or field selections.

```
GET /items/user?fields=id,name,latest_posts.title,published_posts.title&alias[latest_posts]=posts&alias[published_posts]=posts&deep[latest_posts][_sort]=-created_at&deep[latest_posts][_limit]=3&deep[published_posts][_filter][status][_eq]=published
```

Aliases are intentionally scoped to top-level relations:

- `alias[response_name]=existing_relation`
- alias names must be simple identifiers
- aliases cannot overwrite real fields or real relation names
- `fields` and `deep` refer to the alias name

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

**Supported functions:** `count`, `sum`, `avg`, `min`, `max`, `countDistinct`, `sumDistinct`, `avgDistinct`

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

Distinct aggregate example:

```
GET /items/order?aggregate[countDistinct]=customer_id
```

---

## Query Functions

Function fields can be used in filters, sorting, aggregate fields, and `groupBy`:

```
GET /items/order?filter[year(created_at)][_eq]=2026
GET /items/order?sort=-month(created_at)
GET /items/order?aggregate[count]=id&groupBy[]=year(created_at)&groupBy[]=month(created_at)
```

Supported functions:

| Function | Description |
|----------|-------------|
| `year(field)` | Extract year |
| `month(field)` | Extract month |
| `day(field)` | Extract day of month |
| `hour(field)` | Extract hour |
| `date(field)` | Extract date |

---

## Dynamic Variables

Filter values can reference dynamic variables. Built-ins:

| Variable | Value |
|----------|-------|
| `$now` | Current date/time as `Y-m-d H:i:s` |
| `$today` | Current date as `Y-m-d` |

Applications can define their own variables:

```php
RestApiExtension::install($app, [
    'dynamicVariables' => [
        'current_user' => fn () => $auth->id(),
        'current_role' => fn () => $auth->role(),
    ],
]);
```

Then use them in filters:

```
GET /items/post?filter[user_id][_eq]=$current_user
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

Overnight M2M mutations use the same implicit / explicit shapes as other to-many relations. There is no `connect` / `disconnect` payload operation.

Common Overnight schemas represent the **related target** (for example `tag` via `post_tag`). Relation-scope checks use that represented collection’s identities currently linked to the parent. Junction-represented M2M (relation target = through collection) scopes against junction identities instead.

### Assign / retain membership (implicit)

```bash
curl -X POST http://localhost/items/post \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Tagged Post",
    "user_id": 1,
    "status": "published",
    "tags": [1, 3, 5]
}'
```

Listed existing tags are linked (or kept). Tags omitted from a later implicit update are unlinked, not deleted.

### Create related items inline

```bash
curl -X PATCH http://localhost/items/post/1 \
  -H "Content-Type: application/json" \
  -d '{
    "tags": {
        "create": [{"name": "PHP"}, {"name": "REST"}],
        "update": [{"id": 5, "name": "Renamed"}],
        "delete": [1]
    }
}'
```

Explicit `update` / `delete` require the identity to already be in the parent’s relation membership.

See [mutation upgrade notes](./rest-api-mutation-upgrade.md) for migration from legacy connect/disconnect events.

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

The REST API uses a browser-style event model. **Read** operations dispatch a single event per request. **Write** operations dispatch before/after pairs for each bound item in the mutation tree.

### Read events

| Event Class | Event Name | Preventable | Default Action |
|-------------|-----------|-------------|----------------|
| `ItemList` | `restapi.item.list` | Yes | SQL SELECT |
| `ItemGet` | `restapi.item.get` | Yes | SQL SELECT by ID |
| `FileUpload` | `restapi.file.upload` | Yes | None (requires listener) |
| `RequestComplete` | `restapi.request.complete` | No | — |

### Write events — item lifecycle

| Before (preventable) | After | Event Name (before / after) |
|---------------------|-------|----------------------------|
| `ItemCreating` | `ItemCreated` | `restapi.item.creating` / `restapi.item.created` |
| `ItemUpdating` | `ItemUpdated` | `restapi.item.updating` / `restapi.item.updated` |
| `ItemDeleting` | `ItemDeleted` | `restapi.item.deleting` / `restapi.item.deleted` |

Order:

- **before:** parent then children
- **after:** children then parent (only after successful flush)

Before-events expose:

- `getState()` — `MutationStateInterface` façade over the Session representation that will flush. Hooks mutate pending **scalars** with `setValue()` / `setData()` (also written onto that representation). Hooks do **not** see `RecordState`.
- `getKey()` / `getId()` — `ON\Data\Key` identity when available (`getPrimaryKeyValue()` remains as a deprecated alias).
- `getPath()` — nested path segments (empty for root)
- `preventDefault(?array $result = null)` — stop the write (no flush, no after-events). Root may supply an alternate result.

Hook mutability boundaries:

- **Supported:** scalar field changes, including fields absent from the original payload; explicit `null` via `setValue`.
- **Unsupported:** changing primary-key fields on existing items (`IDENTITY_MUTATION_NOT_ALLOWED`); mutating relation membership / normalized relation intent through hooks (relation names are not scalar fields — there is no relation-intent hook API).
- `setData()` replaces the pending overlay map; keys omitted from `setData` are not written again (representation values already set remain unchanged — not an implicit null).
- Identity is `ON\Data\Key` via `PrimaryKey` helpers (`getKey()`), not a RestApi-specific PK value type.

Authorization: implement `AuthorizationAwareEventInterface` listeners (`allow()`, `forbid()`, `requireAuthentication()`).

Relation connect/disconnect events are not part of the Session mutation path. See [mutation upgrade notes](./rest-api-mutation-upgrade.md).

### How writes flow

```
1. Parse + bind → BoundMutation tree on one Session
2. Dispatch before-events (parent → child); reapply hook scalar mutations onto representations
3. Session::sync() + Session::flush()
4. Reload roots via ON\Data Query; markReady(row) on state
5. Dispatch after-events (child → parent)
```

Reads follow the simpler model:

```
1. Dispatch event (listeners run)
2. Check event->isDefaultPrevented()
   → true:  skip default SQL, use event result
   → false: run default SQL, set result on event
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

### Example: Auto-set timestamps on create

```php
use ON\RestApi\Event\ItemCreating;

$app->events->eventDispatcher->subscribeTo('restapi.item.creating', function (ItemCreating $event) {
    if (!$event->isRoot()) {
        return;
    }

    $event->getState()->setValue('created_at', date('Y-m-d H:i:s'));
    $event->getState()->setValue('updated_at', date('Y-m-d H:i:s'));
});
```

### Example: Audit logging on update

```php
use ON\RestApi\Event\ItemUpdating;

$app->events->eventDispatcher->subscribeTo('restapi.item.updating', function (ItemUpdating $event) use ($logger) {
    $logger->info('Item updating', [
        'collection' => $event->getCollection()->getName(),
        'id' => $event->getId(),
        'path' => $event->getPathString(),
    ]);
});
```

### Example: Soft delete

Replace hard delete by preventing the delete node and updating `deleted_at` via the queue:

```php
use ON\RestApi\Event\ItemDeleting;

$app->events->eventDispatcher->subscribeTo('restapi.item.deleting', function (ItemDeleting $event) {
    if (!$event->isRoot()) {
        return;
    }

    $event->getQueue()->queueUpdate(
        $event->getCollection(),
        /* criteria from state PK */,
        ['deleted_at' => date('Y-m-d H:i:s')]
    );
    $event->preventDefault(false);
});
```

For production soft-delete, prefer building update criteria from `$event->getId()->getValues()` (or `PrimaryKey::of($collection)->getValue(...)`).

### Using EventSubscriberInterface

```php
use ON\Event\EventSubscriberInterface;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemUpdating;

class TimestampSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'restapi.item.creating' => [0, 'onItemCreating'],
            'restapi.item.updating' => [0, 'onItemUpdating'],
        ];
    }

    public function onItemCreating(ItemCreating $event): void
    {
        if (!$event->isRoot()) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $event->getState()->setValue('created_at', $now);
        $event->getState()->setValue('updated_at', $now);
    }

    public function onItemUpdating(ItemUpdating $event): void
    {
        if (!$event->isRoot()) {
            return;
        }

        $event->getState()->setValue('updated_at', date('Y-m-d H:i:s'));
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
        $eventDispatcher?->subscribeTo('restapi.item.creating', function (\ON\RestApi\Event\ItemCreating $event) {
            // Custom logic — e.g. $event->getState()->setValue('field', $value);
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
| `countDistinct`, `sumDistinct`, `avgDistinct` | Supported | Supported |
| Query aliases | Top-level relation aliases | Broader alias support |
| Query functions | Date/time functions for filters, sort, aggregate, and grouping | Broader function support |
| Dynamic variables | Built-ins plus app-defined variables | Built-in user/role-aware variables |
| Revision history | Not built-in | Built-in |
| Search | LIKE-based (external via events) | Built-in + external search support |
