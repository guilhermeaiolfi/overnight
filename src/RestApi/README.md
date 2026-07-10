# RestApi Extension — Mutation Architecture

Developer reference for the REST API mutation pipeline after the ON\Data Session cutover.

User-facing API docs: [`docs/extensions/rest-api.md`](../../docs/extensions/rest-api.md).

---

## Pipeline

```text
Directus payload
    → DirectusPayloadParser
    → ToOneMutation /
      ToManyImplicitMutation /
      ToManyExplicitMutation
    → DirectusMutationBinder
    → ON\Data Session and projections
    → before-events
    → Session::flush()
    → after-events
    → ON\Data Query reload
    → Directus response
```

Reads continue to use ON\Data `SelectQuery`. Writes use one Session path only — there is no MutationQueue fallback.

---

## Normalized relation mutations

| Type | Wire shape | Semantics |
|------|------------|-----------|
| `ToOneMutation` | `null` \| identity \| object | clear, assign existing, create/update nested target |
| `ToManyImplicitMutation` | array of identities/objects | **final membership**; omitted current members are **unlinked**, not deleted |
| `ToManyExplicitMutation` | `{ create, update, delete }` | incremental deltas only; `delete` removes the **represented** row |

There is no Directus `connect` / `disconnect` payload operation. Linking existing members uses implicit arrays (or nested identities). Unlinking uses implicit omission.

---

## Binding rules

- RestApi expresses membership intent only (`add` / `remove` / `Session::remove`).
- ON\Data planners own FK updates, through-row insert/delete, and generated-key ordering.
- Relation baselines for implicit reconciliation load through ON\Data mutable queries (`RelationBaselineReader`), not through-table scans in RestApi.
- Nested relation payloads bind recursively on the same Session.
- M2M represented items may be junction items when the relation target is the through collection; target-represented M2M (common Overnight schemas) treat target identities as membership.
- First-of-many relations are read-only for mutations.

---

## Events

`MutationEventPlan` builds separate orders:

- **before:** parent then children (pre-order)
- **after:** children then parent (post-order)

Prevented before-events stop flush. After-events are not emitted on rollback.

---

## Batch atomicity

`batchCreate` / `batchUpdate` / `batchDelete` (and array-body create) share:

```text
one request → one Session → bind all roots → all before-events → one Session::flush() → all after-events → ordered reloads
```

One flush uses the transactional command executor when available, so the batch is atomic.

---

## Package map

```text
src/RestApi/Mutation/
├── MutationCoordinator.php      create/update/delete + batch*
├── DirectusMutationBinder.php   recursive Directus → Session binding
├── RelationBaselineReader.php   current membership via ON\Data queries
├── SessionFactory.php
├── BoundMutation.php / BoundItemState.php
├── Event/MutationEventPlan.php
└── Payload/
    ├── DirectusPayloadParser.php
    ├── ToOneMutation.php
    ├── ToManyImplicitMutation.php
    └── ToManyExplicitMutation.php
```
