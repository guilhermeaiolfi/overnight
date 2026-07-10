# RestApi mutation upgrade notes (ON\Data Session cutover)

## Removed relation connect/disconnect events

The legacy mutation engine emitted relation-specific lifecycle events:

- `RelationConnecting` / `restapi.relation.connecting`
- `RelationConnected` / `restapi.relation.connected`
- `RelationDisconnecting` / `restapi.relation.disconnecting`
- `RelationDisconnected` / `restapi.relation.disconnected`

Those events were removed with `MutationQueue` / relation persistence handlers. They do not match the Directus payload model used by the Session pipeline:

- Implicit to-many arrays express **final membership** (link / unlink), not discrete connect/disconnect commands.
- Explicit `{ create, update, delete }` mutates **represented rows**, not a separate relation-edge API.
- Unlinking an omitted implicit member does **not** delete the related row and does **not** emit a dedicated relation event.

There is **no replacement event** for pure relation membership changes (link/unlink only).

### What remains

Item lifecycle events on represented rows:

| Before | After |
|--------|-------|
| `ItemCreating` | `ItemCreated` |
| `ItemUpdating` | `ItemUpdated` |
| `ItemDeleting` | `ItemDeleted` |

Traversal:

- **before:** parent → children
- **after:** children → parent (only after successful flush)

### How to migrate extensions

| Old behavior | New approach |
|--------------|--------------|
| Listen for `RelationConnected` | Listen for `ItemCreating` / `ItemUpdating` on the related (or junction) collection when the payload creates/updates that row; for pure link of an existing identity, there is no dedicated event |
| Listen for `RelationDisconnected` | Implicit omission unlinks silently at relation-state level; listen for `ItemDeleting` only when the payload uses explicit `delete` |
| `tags: { connect: [1] }` | `tags: [1, …]` (include all desired members) |
| `tags: { disconnect: [2] }` | Omit `2` from the implicit membership array |
| `preventDefault()` on relation events | Use `preventDefault()` on item before-events (`ItemCreating` / `ItemUpdating` / `ItemDeleting`) |

Do not restore fake connect/disconnect events for compatibility.
