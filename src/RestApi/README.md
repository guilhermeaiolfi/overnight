# RestApi Extension — Architecture

Developer reference for the REST API mutation pipeline, handlers, and extension points.

User-facing API docs: [`docs/extensions/rest-api.md`](../../docs/extensions/rest-api.md).

---

## Write lifecycle

Mutations follow a strict four-phase pipeline:

```
file uploads (pre-plan)
  → plan()           build MutationNode tree, normalize relation payloads → MutationPlan
  → commit()         before-events on full plan → schedule after-events → fillQueue()
  → transaction { queue.execute() }
  → dispatchAfterEvents()
```

Reads use a separate path: `QueryPlanner` → relation handlers (`load()`) → no mutation queue.

---

## Vocabulary

| Term | Role |
|------|------|
| `RestApiService` | HTTP orchestration: reads via `QueryPlanner`, writes via planner + queue |
| `QueryPlanner` | Builds handler tree, runs list/get/aggregate queries |
| `RestMutationPlanner` | Plans mutation tree (`planSave`/`planDelete`), commits via `commit()`, dispatches events |
| `MutationPlan` | Readonly result of planning: root `MutationNode` tree, ready for `commit()` |
| `MutationNode` | One entity in the plan tree: operation, state, nested relations |
| `RelationNode` | One relation on a node: handler, payload, planned child nodes |
| `MutationQueue` | Ordered list of insert/update/delete commands; resolves `ValueRef` deps at execute time |
| `MutationState` | Mutable row values for one entity during a mutation |
| `ValueRef` | Deferred field value from another state's PK (cross-row FK wiring) |
| `ChildIntent` | `{ collection, data }` — collection bound at normalize time for child row planning |
| `LinkIntent` | `{ collection, target }` — connect/disconnect target identity |
| `RelationMutationPayload` | Typed `{ create, update, delete, connect, disconnect }` from `normalizePayload()` |
| `Handler` / `HandlerRegistry` | Relation-scoped read + write implementation per relation kind |
| `HandlerFactory` | `relation()` for reads, `mutation()` for writes, `root()` for collection root — one constructor for all |

---

## Package map

```
src/RestApi/
├── RestApiService.php          Entry point for CRUD + list/get
├── RestApiExtension.php        Extension bootstrap
├── Query/
│   ├── QueryPlanner.php        Read orchestration
│   └── Parser/                 Directus-style query → QuerySpec
├── Mutation/
│   ├── RestMutationPlanner.php Plan + commit + events
│   ├── MutationPlan.php        Readonly plan result (root node tree)
│   ├── MutationQueue.php       Execute-phase command list
│   ├── MutationNode.php        Plan tree node (entity)
│   ├── RelationNode.php        Plan tree relation
│   ├── ChildIntent.php         Child row intent (collection + data)
│   ├── LinkIntent.php          Connect/disconnect intent
│   └── RelationMutationPayload.php
├── Handler/
│   ├── HandlerRegistry.php     Relation kind → handler class
│   ├── HandlerFactory.php      Instantiate handlers
│   ├── RootHandler.php         Collection root (read + normalize)
│   ├── HasOneHandler.php       Singular FK-on-target relation
│   ├── HasManyHandler.php      Collection FK-on-target relation
│   ├── BelongsToHandler.php      FK-on-source relation
│   ├── ManyToManyHandler.php   Junction + target relation
│   ├── Read/                   Shared read traits (e.g. SingularRelationRead)
│   └── Mutation/               Write traits per relation kind
├── Resolver/Sql/               SqlDataSource (writes), SqlRestResolver (HTTP)
└── Event/                      Item + relation lifecycle events
```

---

## Plan phase (`RestMutationPlanner`)

1. Split scalar fields from relation input (`MutationInput::splitNodeInput`).
2. `normalizePayload()` on each **relation** handler → `RelationMutationPayload`.
3. Plan child `MutationNode`s from `ChildIntent` entries (collection already resolved).
4. Return a `MutationPlan` wrapping the root node — **no events yet**.

## Commit phase (`commit`)

After planning completes:

1. **Before-events** — depth-first walk (**item → child nodes → relation connect/disconnect**), dispatch `ItemCreating`/`ItemUpdating`/`ItemDeleting`, `RelationConnecting`/`RelationDisconnecting`. Root auth runs first; `allowNested()` on the root cascades to nested item before-events in the same pass.
2. **After-events scheduled** — child nodes → relation events → item (unchanged).
3. **`fillQueue()`** — depth-first: child nodes → `applyRelation()` → `queueRow()` (dependency order unchanged).

Row CRUD never lives in relation handlers. Handlers only interpret relation semantics.

`save()` / `delete()` call `planSave()` / `planDelete()` then `commit()`. Tests and extensions can plan and inspect the tree before committing.

## Queue phase (`fillQueue`)

For each `MutationNode`, depth-first:

1. Recurse into relation child nodes (create/update/delete children).
2. `handler->applyRelation()` — FK wiring, connect/disconnect, pivot links (relation handlers only).
3. `queueRow()` — insert/update/delete for **this** node (planner-owned, not handlers).

---

## Handler model

One **handler class per relation kind/variant**. Each handler implements:

- **Read** (`HandlerInterface`): `configureParserNode()`, `load()`, columns
- **Write** (`RelationMutationHandlerInterface` via `Mutation/*` trait): `normalizePayload()`, `applyRelation()`

| Kind | Handler | Mutation trait |
|------|---------|----------------|
| hasOne | `HasOneHandler` | `HasOneMutation` |
| hasMany | `HasManyHandler` | `HasManyMutation` |
| belongsTo | `BelongsToHandler` | `BelongsToMutation` |
| manyToMany | `ManyToManyHandler` | `ManyToManyMutation` |

For a different M2M shape (e.g. pivot-heavy scope like Cycle's `PivotLoader`), register a new handler pair — e.g. `M2MWithPivotHandler` + `M2MWithPivotMutation` — via `HandlerRegistry::relation()` or `::default()`.

### Register a custom handler

```php
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;

$registry = HandlerRegistry::defaults()
    ->relation('post', 'tags', MyCustomM2MHandler::class);

$factory = new HandlerFactory($registry, $dataSource, $querySpecCompiler);
```

`HandlerFactory::relation()` builds handlers for reads (selection + aliases).
`HandlerFactory::mutation()` builds the same handler class for planning (selection/aliases omitted).
Both paths share `SqlDataSource` and `SqlQuerySpecCompiler` from the factory.

### Custom handler checklist

1. Extend `AbstractRelationHandler` (or an existing handler if behavior is close).
2. Implement read: parser node + `load()`.
3. Add a `Mutation/*` trait (or inline) implementing `normalizePayload()` + `applyRelation()`.
4. Return `ChildIntent` with the correct `collection` for each create/update/delete entry (critical for M2M pivot vs target, polymorphic relations).
5. Register in `HandlerRegistry`.
6. Add tests under `tests/RestApi/`.

### Polymorphic example (sketch)

Input with a `type` field selects the target collection at normalize time:

```php
public function normalizePayload(...): RelationMutationPayload {
    $payload = $this->emptyPayload();
    foreach ($input as $item) {
        $collection = $this->resolveMorphCollection($item['type']);
        $payload->create[] = new ChildIntent($collection, $item);
    }
    return $payload;
}
```

The planner plans child nodes from `ChildIntent.collection` — no `mutationCollection()` lookup at plan time.

---

## Mutation events

### Item events (per node in the tree)

| Before (preventable) | After | Event name |
|---------------------|-------|------------|
| `ItemCreating` | `ItemCreated` | `restapi.item.creating` / `.created` |
| `ItemUpdating` | `ItemUpdated` | `restapi.item.updating` / `.updated` |
| `ItemDeleting` | `ItemDeleted` | `restapi.item.deleting` / `.deleted` |

Before-events receive `MutationState` and `MutationQueue`. Listeners may:

- Authorize via `AuthorizationAwareEventInterface` (`allow()`, `forbid()`, …)
- Modify pending values: `$event->getState()->setValue('field', $value)`
- Enqueue extra work: `$event->getQueue()->queueUpdate(...)`
- Short-circuit: `$event->preventDefault($resultRow)`

After-events fire post-commit (successful delete/update/create).

### Relation events (connect/disconnect)

| Before | After | Event name |
|--------|-------|------------|
| `RelationConnecting` | `RelationConnected` | `restapi.relation.connecting` / `.connected` |
| `RelationDisconnecting` | `RelationDisconnected` | `restapi.relation.disconnecting` / `.disconnected` |

Before-events expose `getQueue()` (same `MutationQueue` as item before-events). Path arrays identify nested location, e.g. `['tags', 'connect', 1]`.

### Read events (unchanged)

`ItemList`, `ItemGet`, `FileUpload`, `RequestComplete` — see user docs.

---

## Dependencies

| Component | Read | Write |
|-----------|------|-------|
| `RestApiService` | `QueryPlannerInterface` | `DataSourceInterface` (mutations require `SqlDataSource` for plan-phase reads) |
| Handlers | `SqlDataSource`, `SqlQuerySpecCompiler`, `AliasRegistry`, `RelationSelection` | Same handler deps via `HandlerFactory`; `normalizePayload()` uses `$this->dataSource` |

---

## Testing

```bash
php vendor/bin/phpunit tests/RestApi/
```

Key suites: `RestApiServiceTest` (mutations + events), `SqlRestResolverTest` (HTTP + reads), `HandlerRegistryTest` (handler registration).

---

## Known limitations

### Plan-phase duplicate check (race window)

`RestMutationPlanner::assertCreateIdAvailable()` runs during **plan**, before the transaction. Two concurrent creates with the same explicit PK can both pass the check if neither row exists yet at plan time. The database unique constraint still rejects the loser at execute time (`DUPLICATE` / 409).

Moving the check into the first queue command (inside the transaction) is a possible follow-up; today callers should rely on DB constraints for correctness under concurrency.

### Polymorphic relations

Not implemented in the default handler set. Extension pattern:

1. Register a custom handler via `HandlerRegistry::relation()`.
2. In `normalizePayload()`, resolve the target collection from input (e.g. a `type` field) and return `ChildIntent` with that collection bound.
3. Implement read (`load()` + parser) and write (`applyRelation()`) for the same relation scope — same rules as M2M pivot variants.

See the polymorphic sketch in [Handler model](#handler-model) above. A dedicated `M2MWithPivotHandler` (or similar) should be added when pivot-scoped read/write diverges from `ManyToManyHandler`; register it per collection/relation, not as a global default.
