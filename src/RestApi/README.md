# RestApi Extension — Architecture

Developer reference for the REST API mutation pipeline, handlers, and extension points.

User-facing API docs: [`docs/extensions/rest-api.md`](../../docs/extensions/rest-api.md).

---

## Read vs write pipelines

**Reads** and **writes** share the same handler registry but use symmetric spec pipelines:

```
Reads:  HTTP params → QueryParser → QuerySpec → QueryNormalizer → QueryPlanner → storage rows
                                                                              ↓
Writes: JSON body  → PayloadParser → MutationSpec → PayloadNormalizer → MutationPlanner → storage rows
                                                                              ↓
        RestApiService.formatResponse*  →  PHP (default) | wire (serialize) | storage (raw)
```

Swapping Directus for another wire format means swapping `DirectusQueryParser` / `DirectusPayloadParser` — the planner, queue, and handler apply layer stay unchanged.

---

## Write lifecycle

Mutations follow a strict four-phase pipeline:

```
file uploads (pre-plan)
  → plan()           parse + normalize payload → build MutationNode tree → MutationPlan
  → commit()         before-events on full plan → schedule after-events → fillQueue()
  → transaction { queue.execute() }
  → dispatchAfterEvents()
```

---

## Vocabulary

| Term | Role |
|------|------|
| `RestApiService` | HTTP orchestration; shapes responses via `formatResponseRow()` (hydrate/serialize) |
| `QueryPlanner` | Builds handler tree, runs list/get/aggregate — returns storage rows only |
| `DirectusPayloadParser` | Wire-format parser: JSON → `MutationSpec` (may include `BasicRelationAction`) |
| `PayloadNormalizer` | Walks the mutation tree; delegates relation payload normalization to handlers |
| `MutationSpec` / `MutationNodeSpec` | Normalized entity tree: scalars + `RelationPayload` list |
| `RelationPayload` | One relation occurrence: flat `list<RelationAction>` |
| `RelationAction` | `CreateAction`, `UpdateAction`, `DeleteAction`, `ConnectAction`, `DisconnectAction` |
| `RestMutationPlanner` | Plans mutation tree (`planSave`/`planDelete`), commits via `commit()`, dispatches events |
| `MutationPlan` | Readonly result of planning: root `MutationNode` tree, ready for `commit()` |
| `MutationNode` | One entity in the plan tree: operation, state, nested relations |
| `RelationNode` | One relation on a node: handler, `RelationPayload`, planned child nodes |
| `MutationQueue` | Ordered list of insert/update/delete commands; resolves `ValueRef` deps at execute time |
| `MutationState` | Mutable row values for one entity during a mutation |
| `ValueRef` | Deferred field value from another state's PK (cross-row FK wiring) |
| `Handler` / `HandlerRegistry` | Relation-scoped read + write implementation per relation kind |
| `HandlerFactory` | `relation()` reads, `mutation()` normalize + apply |

---

## Package map

```
src/RestApi/
├── RestApiService.php
├── Query/
│   ├── QueryPlanner.php
│   └── Parser/                 Directus-style query → QuerySpec
├── Payload/
│   ├── Parser/                 DirectusPayloadParser → MutationSpec
│   ├── PayloadNormalizer.php   tree walk + handler delegation
│   ├── Action/                 CreateAction, ConnectAction, BasicRelationAction, …
│   └── Node/                   MutationNodeSpec, RelationPayload, MutationSpec
├── Mutation/
│   ├── RestMutationPlanner.php Plan + commit + events
│   ├── MutationPlan.php
│   ├── MutationQueue.php
│   ├── MutationNode.php
│   └── RelationNode.php
├── Handler/
│   ├── HandlerRegistry.php
│   ├── HandlerFactory.php
│   ├── HasOneHandler.php …     Read + applyRelation()
│   ├── Read/
│   └── Mutation/               Normalize + apply traits (HasManyNormalize, ForeignKeyOnTargetApply, …)
└── Event/
```

---

## Plan phase (`RestMutationPlanner`)

1. **`DirectusPayloadParser::parse()`** — split scalars vs relations; detailed → typed actions; basic → `BasicRelationAction`.
2. **`PayloadNormalizer::normalize()`** — for each relation, `HandlerFactory::mutation()` returns a handler that normalizes the payload in one call (`normalizeRelation()`).
3. **`planFromSpec()`** — walk `MutationNodeSpec`; entity actions → child `MutationNode`s; link actions stay on `RelationPayload.actions` for `applyRelation()`.
4. Return `MutationPlan` — **no events yet**.

## Commit phase (`commit`)

1. **Before-events** — depth-first: item → child nodes → relation connect/disconnect (from `RelationPayload.actions`).
2. **After-events scheduled** — child nodes → relation events → item.
3. **`fillQueue()`** — depth-first: child nodes → `applyRelation()` → `queueRow()`.

Row CRUD never lives in relation handlers. Handlers only interpret relation semantics at apply time.

---

## Payload actions (Option B)

Each `RelationPayload` holds a flat `list<RelationAction>`. The planner uses a two-pass model:

| Pass | Action types | Work |
|------|-------------|------|
| Entity | `CreateAction`, `UpdateAction`, `DeleteAction` | Plan nested `MutationNode` trees (recursive) |
| Link | `ConnectAction`, `DisconnectAction` | `applyRelation()` — FK wiring, pivot inserts |

Basic vs detailed is a **parser/normalizer** concern only. By plan time, `BasicRelationAction` is gone and every action is fully typed.

---

## Handler model

One **handler class per relation kind**. Each handler implements:

- **Read** (`HandlerInterface`): `configureParserNode()`, `load()`
- **Write** (`RelationMutationHandlerInterface`): `normalizeRelation()`, `applyRelation()`

| Kind | Handler | Normalize trait | Apply trait |
|------|---------|-----------------|-------------|
| hasOne | `HasOneHandler` | `HasOneNormalize` | `ForeignKeyOnTargetApply` |
| hasMany | `HasManyHandler` | `HasManyNormalize` | `ForeignKeyOnTargetApply` |
| belongsTo | `BelongsToHandler` | `BelongsToNormalize` | `BelongsToApply` |
| manyToMany | `ManyToManyHandler` | `ManyToManyNormalize` | `ManyToManyApply` |

### Register a custom handler

```php
$registry = HandlerRegistry::defaults()
    ->relation('post', 'tags', MyCustomM2MHandler::class);

$factory = new HandlerFactory($registry, $dataSource, $querySpecCompiler);
```

### Custom handler checklist

1. Extend `AbstractRelationHandler` (or an existing handler if behavior is close).
2. Implement read: parser node + `load()`.
3. Add a `*Normalize` trait (use `RelationPayloadNormalizeEntry` or extend an existing normalize trait).
4. Add a `*Apply` trait implementing `applyRelation()` reading from `RelationPayload.actions`.
5. Register in `HandlerRegistry`.
6. Add tests under `tests/RestApi/`.

### Polymorphic example (sketch)

Resolve target collection inside `coerceBasicActions()` when normalizing basic/detailed create actions:

```php
protected function coerceBasicActions(MutationContext $context, BasicRelationAction $basic): array
{
    $actions = [];
    foreach ($basic->items as $item) {
        $collection = $this->resolveMorphCollection($item['type'] ?? '');
        $actions[] = new CreateAction(collection: $collection->getName(), data: $item);
    }
    return $actions;
}
```

---

## Mutation events

### Item events (per node in the tree)

| Before (preventable) | After | Event name |
|---------------------|-------|------------|
| `ItemCreating` | `ItemCreated` | `restapi.item.creating` / `.created` |
| `ItemUpdating` | `ItemUpdated` | `restapi.item.updating` / `.updated` |
| `ItemDeleting` | `ItemDeleted` | `restapi.item.deleting` / `.deleted` |

### Relation events (connect/disconnect)

Dispatched from `ConnectAction` / `DisconnectAction` on `RelationPayload.actions`. Path arrays identify nested location, e.g. `['tags', 'connect', 1]`.

---

## Testing

```bash
php vendor/bin/phpunit tests/RestApi/
```

Key suites: `RestApiServiceTest`, `SqlRestResolverTest`, `PayloadParserTest`, `HandlerRegistryTest`.

---

## Known limitations

### Plan-phase duplicate check (race window)

`RestMutationPlanner::assertCreateIdAvailable()` runs during **plan**, before the transaction. Concurrent creates with the same explicit PK can both pass until the DB unique constraint rejects one at execute time.

### Polymorphic relations

Not in the default handler set. Register a custom handler with normalize + apply traits; resolve target collection in `coerceBasicActions()` / `resolvePayloadAction()`.
