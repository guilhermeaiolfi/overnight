# ON\Data → Cycle Schema Migration

Transitional notes for `ON\ORM\Compiler\OnDataCycleRegistryGenerator`, which compiles `ON\Data\Definition\Registry` into a Cycle schema registry.

The legacy `ON\ORM\Compiler\CycleRegistryGenerator` remains the production path until parity is proven and application definitions migrate.

## Generators

| Generator | Input | Status |
|-----------|--------|--------|
| `CycleRegistryGenerator` | `ON\ORM\Definition\Registry` | Production (unchanged) |
| `OnDataCycleRegistryGenerator` | `ON\Data\Definition\Registry` | Side-by-side / tests only |

`OnDataCycleRegistryGenerator` is container-resolvable under its own class. It is **not** wired into `CycleDatabaseFactory` in this phase.

## Collection metadata

| Legacy (`ON\ORM`) | ON\Data | Cycle write | Classification |
|-------------------|---------|-------------|----------------|
| `getName()` | `getName()` | `Entity::setRole()` | direct mapping |
| `getTable()` | `getTable()` | `setTableName()` + `linkTable` | direct mapping |
| `getDatabase()` | `getDatabase()` | `setDatabase()` + `linkTable` | direct mapping |
| `getEntity()` | `getEntity()` | `setClass()` | direct mapping |
| `getMapper()` default `StdMapper` | `getMapper()` default `null` | `setMapper()` | mapping with changed semantics (defaults differ; set explicitly for parity) |
| `getRepository()` | `getRepository()` | `setRepository()` | direct mapping |
| `getScope()` | `getScope()` | `setScope()` | direct mapping |
| `getSource()` default `ON\ORM\Select\Source` | `getSource()` default `null` | `setSource()` | mapping with changed semantics (defaults differ) |
| Field-level `primaryKey(true)` | Collection `primaryKey(...)` + `Field::isPrimaryKey()` | `Field::setPrimary()` | mapping with changed semantics (PK ownership moved to collection) |
| `hidden`, `note`, `description`, `parentCollection`, metadata bag | Same keys exist | *(ignored)* | obsolete under Cycle schema (consumed elsewhere) |
| `getInheritedCollections()` stub | `parentCollection` | *(ignored)* | requires a later decision (STI) |

## Field metadata

| Legacy | ON\Data | Cycle write | Classification |
|--------|---------|-------------|----------------|
| Field map key / `getName()` | Field map key / `getName()` | Cycle field name | direct mapping |
| `getColumn()` | `getColumn()` | `setColumn()` | direct mapping |
| `getType()` + `ON\Mapper\Field\*` | `getType()` + `ON\Data\Mapper\FieldTypeInterface` via `ConversionGateway` | `setType()` | mapping with changed semantics (mapper package swapped; resolution rules preserved where possible) |
| `isPrimaryKey()` | `isPrimaryKey()` (from collection PK) | `setPrimary()` | direct mapping |
| `isNullable()` | `isNullable()` | `OPT_NULLABLE` / default null | direct mapping |
| `hasDefault()` / `getDefault()` | `hasDefault()` / `getDefault()` on `Field` | `OPT_DEFAULT` | direct mapping |
| `castDefault()` | `castDefault()` on `Field` | `OPT_CAST_DEFAULT` | direct mapping |
| `getTypecast()` | `getTypecast()` | `setTypecast()` | direct mapping |
| `getMaxLength()` for bare `string` | `getMaxLength()` | `string(N)` | direct mapping |
| Serial types / any primary → `ON_INSERT` | Serial types / `isAutoIncrement()` / primary → `ON_INSERT` | `setGenerated(ON_INSERT)` | mapping with changed semantics (`auto_increment` is honored in addition to legacy rules) |
| `unique`, `indexed`, `comment`, `hidden`, validation, display | Present on ON\Data | *(ignored)* | not represented by Cycle schema generator (same as legacy) |
| `default_value` (SchemaTrait) | `getDefaultValue()` | *(ignored)* | requires a later decision (legacy also ignored it; runtime `default` is used) |
| `readonlySchema` / column attributes | Absent | *(ignored)* | obsolete under ON\Data |

### Storage type resolution

`OnDataCycleRegistryGenerator` must **not** use `ON\Mapper\Field\FieldTypeRegistry`.

Order:

1. If `getType()` is an `ON\Data\Mapper\FieldTypeInterface` class → `::getStorageType()`
2. Reject unknown PHP classes that are not field handlers, `DateTimeInterface`, or enums
3. Resolve aliases / backed enums through the configured `ConversionGateway` `MapperManager`
4. Apply handler storage type only when the current type string is **not** already a known Cycle column type (preserves `primary`, `bigprimary`, etc.)
5. Preserve parameterized types such as `string(32)`
6. Expand bare `string` to `string({maxLength})`

`DateTimeInterface` subclasses are mapped to `datetime` for parity with the legacy registry convention. Arbitrary non-handler classes throw.

## Relation metadata

| Legacy | ON\Data | Cycle write | Classification |
|--------|---------|-------------|----------------|
| `getCollectionName()` | `getCollectionName()` | `setTarget()` | direct mapping |
| `getLoadStrategy()` | `getLoadStrategy()` | option `load` | direct mapping |
| `isCascade()` | `isCascade()` | option `cascade` | direct mapping |
| `isNullable()` | `isNullable()` | option `nullable` | direct mapping |
| `innerKeys()` / `outerKeys()` | `getInnerKeys()` / `getOuterKeys()` | `innerKey` / `outerKey` (columns) | direct mapping (API rename only) |
| Field name → column | Field name → `getColumn()` | scalar or `string[]` | direct mapping |
| M2M `through` collection | `M2MRelation::getThrough()` | option `through` | direct mapping |
| `throughInnerKeys()` / `throughOuterKeys()` | `getInnerKeys()` / `getOuterKeys()` on through | `throughInnerKey` / `throughOuterKey` | direct mapping (API rename only) |
| Composite M2M keys | Supported | arrays preserved | direct mapping (no artificial single-key restriction) |
| `where`, `orderBy`, custom `loader` | Present | *(ignored)* | obsolete under Cycle schema (query/runtime layer) |
| `collection_factory` | Present | *(ignored)* | not represented by Cycle schema generator |
| Morphed / embedded relations | Absent in ON\Data | — | not represented by ON\Data |

### Relation type resolution

Resolved via **explicit class / interface checks**, not short-name reflection:

| ON\Data relation | Cycle type | Classification |
|------------------|------------|----------------|
| `HasOneRelation` + `isExclusive()` | `hasOne` | direct mapping |
| `HasOneRelation` / `BelongsToRelation` + nullable | `belongsTo` | direct mapping |
| `HasOneRelation` + non-nullable + non-exclusive | `refersTo` | direct mapping |
| `HasManyRelation` | `hasMany` | direct mapping |
| `M2MRelation` | `manyToMany` | direct mapping |
| `FirstOfManyRelation` | **unsupported** | requires a later decision — see below |
| Any other relation class | **throws** | not represented by ON\Data / unsupported |

### First-of-many

`FirstOfManyRelation` is a has-many with single cardinality and a specialized loader. Cycle has no first-of-many relation type.

The legacy generator silently emitted `hasMany`, which drops single-cardinality semantics at the schema layer.

**Decision for this phase:** `OnDataCycleRegistryGenerator` throws `UnsupportedOnDataFeatureException` for `FirstOfManyRelation`. Do not approximate it as ordinary `hasMany`. Runtime first-of-many support remains outside Cycle schema compilation and needs a later migration plan.

## Key rules

- Relation keys are logical **field names**; the generator resolves them to physical **columns**.
- Single-column keys collapse to a string; composite keys remain `string[]`.
- Inner/outer key counts must match; missing fields throw a targeted exception.
- Collection primary keys come only from `CollectionInterface::primaryKey()` / `getPrimaryKey()`.

## Wiring constraints (this phase)

- Do not replace `CycleRegistryGenerator` in `CycleDatabaseFactory`.
- Do not emit `OrmConfigureEvent` from the new generator.
- Do not delete `ON\ORM\Definition` or migrate RestApi / QuerySpec / GraphQL yet.
- Consume the cached `ON\Data\Definition\Registry` from `DefinitionRegistryProvider` when resolving the new generator from the container.
