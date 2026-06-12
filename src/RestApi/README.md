# RestApi Extension — Architecture

Developer reference for the REST API mutation pipeline, handlers, and extension points.

User-facing API docs: [`docs/extensions/rest-api.md`](../../docs/extensions/rest-api.md).

---

## Read vs write pipelines

**Reads** and **writes** share the same handler registry but use symmetric spec pipelines:

```
Reads:  HTTP params → QueryParser → QuerySpec → QueryNormalizer → action read logic → storage rows
                                                                              ↓
Writes: JSON body  → PayloadParser → MutationSpec → PayloadNormalizer → Directus mutation actions → storage rows
                                                                              ↓
        Directus actions format response rows → PHP (default) | wire (serialize) | storage (raw)
```

Swapping Directus for another wire format means swapping `DirectusQueryParser` / `DirectusPayloadParser` — the planner, queue, and handler apply layer stay unchanged.

---

## Write lifecycle

Mutations follow a strict four-phase pipeline:

```
file uploads (pre-plan)
  → build node       parse + normalize payload → build MutationNode tree
  → fill queue       dispatch before-hooks → queue mutations → schedule after-hooks
  → transaction { queue.execute() }
  → flush after-hooks
```

---

## Vocabulary

| Term | Role |
|------|------|
| `Directus\Action\*` | HTTP/action orchestration; shapes responses via hydrate/serialize helpers |
| Directus read actions | Build handler trees, run list/get/aggregate, and return storage rows |
| `DirectusPayloadParser` | Wire-format parser: JSON → `MutationSpec` (may include `BasicRelationAction`) |
| `PayloadNormalizer` | Walks the mutation tree; delegates relation payload normalization to handlers |
| `MutationSpec` / `MutationNodeSpec` | Normalized entity tree: scalars + `RelationPayload` list |
| `RelationPayload` | One relation occurrence: flat `list<RelationAction>` |
| `RelationAction` | `CreateAction`, `UpdateAction`, `DeleteAction`, `ConnectAction`, `DisconnectAction` |
| Directus mutation actions | Build mutation trees, commit through the queue, and dispatch collection hooks |
| `MutationNodeBuilder` | Builds a root `MutationNode` tree from a normalized `MutationSpec` |
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
├── Action/                     Generic action router and action interface
├── Directus/Action/            Directus-compatible list/get/create/update/delete actions
├── Query/
│   └── Parser/                 Directus-style query → QuerySpec
├── Payload/
│   ├── Parser/                 DirectusPayloadParser → MutationSpec
│   ├── PayloadNormalizer.php   tree walk + handler delegation
│   ├── Action/                 CreateAction, ConnectAction, BasicRelationAction, …
│   └── Node/                   MutationNodeSpec, RelationPayload, MutationSpec
├── Mutation/
│   ├── MutationNodeBuilder.php
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

## Plan Phase

1. **`DirectusPayloadParser::parse()`** — split scalars vs relations; detailed → typed actions; basic → `BasicRelationAction`.
2. **`PayloadNormalizer::normalize()`** — for each relation, `HandlerFactory::mutation()` returns a handler that normalizes the payload in one call (`normalizeRelation()`).
3. **`MutationNodeBuilder::fromSpec()`** — walk `MutationNodeSpec`; entity actions → child `MutationNode`s; link actions stay on `RelationPayload.actions` for `applyRelation()`.
4. Return the root `MutationNode` — **no hooks yet**.

## Commit phase (`commit`)

1. **Before-hooks** — depth-first: item → child nodes → relation connect/disconnect (from `RelationPayload.actions`).
2. **`MutationQueue::fill()`** — depth-first: child nodes → `applyRelation()` → `queueNode()`.
3. **After-hooks scheduled** — relation hooks and item hooks are flushed after commit.

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

Key suites: `RestActionRouterTest`, `SqlRestResolverTest`, `PayloadParserTest`, `HandlerRegistryTest`.

---

## Known limitations

### Plan-phase duplicate check (race window)

Directus mutation actions check explicit create IDs during **plan**, before the transaction. Concurrent creates with the same explicit PK can both pass until the DB unique constraint rejects one at execute time.

### Polymorphic relations

Not in the default handler set. Register a custom handler with normalize + apply traits; resolve target collection in `coerceBasicActions()` / `resolvePayloadAction()`.
