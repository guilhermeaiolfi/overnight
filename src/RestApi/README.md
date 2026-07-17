# RestApi Extension — Mutation Architecture

Developer reference for the REST API mutation pipeline after the ON\Data Session cutover.

User-facing API docs: [`docs/extensions/rest-api.md`](../../docs/extensions/rest-api.md).  
Upgrade notes: [`docs/extensions/rest-api-mutation-upgrade.md`](../../docs/extensions/rest-api-mutation-upgrade.md).

---

## Pipeline

```text
Directus payload
    → DirectusPayloadParser
    → ToOneMutation /
      ToManyImplicitMutation /
      ToManyExplicitMutation
    → DirectusMutationBinder
    → ON\Data Session save API (update/create/identify/remove)
      + relation state (ToManyRelationState::full for implicit)
    → before-events
    → Session::sync() + Session::flush()
    → after-events
    → ON\Data Query reload
    → Directus response
```

Reads continue to use ON\Data `SelectQuery`. Writes use one Session path only — there is no MutationQueue fallback.

Existing rows are loaded with `SelectQuery` + `writable($session)` (schema comes from the query). Pure creates and field overlays use `SelectQuery::projection()`. Tracked shapes can be reused via `Session::schemaOf()`.

---

## Normalized relation mutations

| Type | Wire shape | Semantics |
|------|------------|-----------|
| `ToOneMutation` | `null` \| identity \| object | clear, assign existing, create/update nested target |
| `ToManyImplicitMutation` | array of identities/objects | **final membership**; omitted current members are **unlinked**, not deleted |
| `ToManyExplicitMutation` | `{ create, update, delete }` | incremental deltas only; `delete` removes the **represented** row |

There is no Directus `connect` / `disconnect` payload operation. Linking existing members uses implicit arrays (or nested identities). Unlinking uses implicit omission.

### Existing related-item validation

Any payload that references an existing related identity is verified through `ItemRepository` / ON\Data before flush:

| Path | Existence | Relation scope |
|------|-----------|----------------|
| To-one scalar / object identity | Required | N/A (assignment) |
| Implicit identity (with or without updates) | Required | **Not** required — may assign an unrelated existing item |
| Explicit `update` / `delete` | Required | **Required** — must be in `RelationBaseline` for that owner+relation |
| Nested identities | Same rules recursively | Same rules recursively |

Errors:

| Code | Meaning |
|------|---------|
| `RELATED_NOT_FOUND` | Represented identity does not exist |
| `INVALID_RELATION_TARGET` | Exists but is not a current member of this relation |
| `DUPLICATE_RELATED_IDENTITY` | Same normalized identity appears twice in one relation payload |
| `IDENTITY_MUTATION_NOT_ALLOWED` | Before-hook tried to change an existing primary key |
| `MUTATION_PREVENTED` | Before-hook called `preventDefault()` without an alternate root result |

Duplicate detection uses `ON\Data\Key` normalization (canonical PK field order), including composite keys.

### M2M representation

- Target-represented M2M (common Overnight schemas): baseline and scope use **target** identities currently linked to the parent.
- Junction-represented M2M (relation collection = through table): baseline and scope use **junction** identities. Do not scope only by nested target FK.

---

## Binding rules

- RestApi expresses membership intent only (`add` / `remove` / `Session::remove`).
- ON\Data planners own FK updates, through-row insert/delete, and generated-key ordering.
- Relation baselines for implicit reconciliation and explicit scope load through ON\Data writable queries (`RelationBaselineReader` → `RelationBaseline`), not through-table scans in RestApi.
- Nested relation payloads bind recursively on the same Session.
- First-of-many relations are read-only for mutations.
- Request-local identity lookup cache is cleared per coordinator operation (shared across batch roots).
- The cache is a **committed-row** snapshot only, plus identities already scheduled for `Session::remove()` in earlier batch roots (treated as missing). Pending Session creates in earlier roots do not populate it. Referencing an identity that another root in the same batch is creating (manual PK) fails with `RELATED_NOT_FOUND` — that cross-root pattern is unsupported by design.

---

## Events

`MutationEventPlan` builds separate orders:

- **before:** parent then children (pre-order)
- **after:** children then parent (post-order)

`preventDefault()` on a before-event stops further child before-events for that plan, skips flush, and skips after-events. After-events are not emitted on rollback.

### Hook mutability

| Supported | Unsupported |
|-----------|-------------|
| Scalar `setValue` / `setData` | PK changes on existing items |
| Hook-only `setMetadata` / `getMetadata` / `hasMetadata` (not flushed) | Relation membership / nested intent |
| Hook-added scalar fields | Relation membership / relation-intent changes |
| Explicit null scalars | Restoring removed Relation* events |

---

## Batch atomicity

`batchCreate` / `batchUpdate` / `batchDelete` (and array-body create) share:

```text
one request → one Session → bind all roots → all before-events → one Session::flush() → all after-events → ordered reloads
```

One flush uses the transactional command executor when available, so the batch is atomic. A failure while binding or flushing any root rolls back the whole batch; no after-events fire.

---

## Package map

```text
src/RestApi/Mutation/
├── MutationCoordinator.php      create/update/delete + batch*
├── DirectusMutationBinder.php   recursive Directus → Session binding
├── MutationSchemaFactory.php    writable loads + create/overlay projections
├── RelationBaselineReader.php   current membership via ON\Data queries
├── RelationBaseline.php         normalized identity baseline
├── SessionFactory.php
├── BoundMutation.php / BoundItemState.php
├── Event/MutationEventPlan.php
└── Payload/
    ├── DirectusPayloadParser.php
    ├── ToOneMutation.php
    ├── ToManyImplicitMutation.php
    └── ToManyExplicitMutation.php
```
