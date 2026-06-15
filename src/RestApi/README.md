# RestApi Extension — Architecture

Developer reference for the REST API mutation pipeline, handlers, and extension points.

User-facing API docs: [`docs/extensions/rest-api.md`](../../docs/extensions/rest-api.md).

---

## Read vs write pipelines

**Reads** and **writes** share the same handler registry but use symmetric compiler-style pipelines:

```
Reads:  HTTP params → QueryParser → QuerySpec → QueryNormalizer → action read logic → storage rows
                                                                              ↓
Writes: JSON body  → DirectusPayloadParser → RecordStoreCompiler passes → OperationQueue → storage rows
                                                                              ↓
        Directus actions format response rows → PHP (default) | wire (serialize) | storage (raw)
```

Swapping Directus for another wire format means swapping `DirectusQueryParser` / `DirectusPayloadParser` — the compiler passes, queue, and handler apply layer stay unchanged.

---

## Write lifecycle

Mutations follow a strict four-phase pipeline:

```
compile mutation
  → parse payload    raw RecordNode tree → compiler passes → executable RecordNode tree
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
| `MergeMutationInput` | First compiler pass; folds multipart uploads into the raw input payload |
| `ParseDirectusPayload` | Parser pass; converts wire-format input into the initial raw `RecordNode` tree |
| `DirectusPayloadParser` | Wire-format parser: JSON → raw `RecordNode` tree with relation child `RecordNode`s |
| `RecordStoreCompiler` | Runs ordered passes that enrich one evolving record store |
| `HydrationPassInterface` | Extension point for compile-time mutation lowering |
| `MutationInputPreparer` | Prepares request payloads before compilation: merges multipart files and resolves `FileUpload` hooks recursively |
| Directus write actions | Compile record stores, commit through the queue, and dispatch collection hooks |
| `RecordNode` | One entity in the plan tree: operation, state, nested relations |
| `RelationNode` | One relation on a node: relation definition, handler, and planned child nodes |
| `OperationQueue` | Ordered list of insert/update/delete commands; resolves `ValueRef` deps at execute time |
| `NodeState` | Mutable row values for one entity during a mutation |
| `ValueRef` | Deferred field value from another state's PK (cross-row FK wiring) |
| `Handler` / `HandlerRegistry` | Relation-scoped read + write implementation per relation kind |
| `HandlerFactory` | `relation()` reads, `mutation()` apply-time relation handler |

---

## Package map

```
src/RestApi/
├── Action/                     Generic action router and action interface
├── Directus/Action/            Directus-compatible list/get/create/update/delete actions
├── Query/
│   └── Parser/                 Directus-style query → QuerySpec
├── Payload/
│   └── Parser/                 DirectusPayloadParser → raw RecordNode tree
├── Mutation/
│   ├── Compiler/               RecordStoreCompiler + ordered compiler passes
│   ├── OperationQueue.php
│   ├── RecordNode.php
│   └── RelationNode.php
├── Handler/
│   ├── HandlerRegistry.php
│   ├── HandlerFactory.php
│   ├── HasOneHandler.php …     Read + applyRelation()
│   ├── Read/
│   └── Mutation/               Apply traits (ForeignKeyOnTargetApply, BelongsToApply, …)
└── Event/
```

---

## Plan Phase

1. **`MergeMutationInput`** — merge multipart files into the raw payload before parsing.
2. **`ParseDirectusPayload`** — split scalars vs relations and return a raw `RecordNode` tree whose relation children are also `RecordNode`s.
3. **`RecordStoreCompiler` passes** — attach relation definitions, resolve operations/state, hydrate Cycle records, let relation handlers reconcile relation children, and validate the plan.
4. Return the compiled root `RecordNode` — **no item/relation hooks yet**.

## Commit phase (`commit`)

1. **Before-hooks** — depth-first across the compiled record graph and relation children.
2. **Cycle commit + deferred relation commands** — persist the Cycle graph, then let handlers queue relation-specific work such as pivot-row writes.
3. **After-hooks scheduled** — relation hooks and item hooks are flushed after commit.

Row CRUD never lives in relation handlers. Handlers only interpret relation semantics at apply time.

---

## Relation children

Each `RelationNode` holds a flat `list<RecordNode>`. Relation children start as parsed input or omitted current rows, then compiler passes and relation handlers enrich the same nodes with:

- desired vs omitted intent
- current and input identity
- compile mode (`create`, `upsert`, `delete`, ...)
- compile metadata telling whether the child is only relation intent or a concrete nested record mutation

Basic vs detailed Directus payloads are normalized into this child model during parsing and compile-time hydration.

---

## Handler model

One **handler class per relation kind**. Each handler implements:

- **Read** (`HandlerInterface`): `configureParserNode()`, `load()`
- **Write** (`RelationMutationHandlerInterface`): `applyRelation()`

| Kind | Handler | Apply trait |
|------|---------|-------------|
| hasOne | `HasOneHandler` | `ForeignKeyOnTargetApply` |
| hasMany | `HasManyHandler` | `ForeignKeyOnTargetApply` |
| belongsTo | `BelongsToHandler` | `BelongsToApply` |
| manyToMany | `ManyToManyHandler` | `ManyToManyApply` |

### Register a custom handler

```php
$registry = HandlerRegistry::defaults()
    ->relation('post', 'tags', MyCustomM2MHandler::class);

$factory = new HandlerFactory($registry, $dataSource, $records, $querySpecCompiler);
```

### Custom handler checklist

1. Extend `AbstractRelationHandler` (or an existing handler if behavior is close).
2. Implement read: parser node + `load()`.
3. Add an apply trait implementing `applyRelation()` over compiled relation child `RecordNode`s.
4. Add or replace compiler passes when compile-time relation behavior cannot be expressed generically.
5. Register in `HandlerRegistry`.
6. Add tests under `tests/RestApi/`.

### Polymorphic example (sketch)

Resolve target collection inside a compiler pass when lowering parsed relation children:

```php
public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
{
    assert($subject instanceof RecordNode);
    // Walk relation children and rewrite morph payloads before child nodes are completed.
    return $subject;
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

### Relation events

Dispatched from compiled relation child intent during commit. Path arrays still identify nested location.

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

Not in the default handler set. Register a custom handler with an apply trait and add a compiler pass to resolve target collections during relation-child compilation.
