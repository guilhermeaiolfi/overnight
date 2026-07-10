# ON\Data Definition Architecture

Overnight uses **`ON\Data\Definition\Registry`** as the sole entity-definition registry. Legacy `ON\ORM\Definition` has been removed. Cycle ORM schema, RestApi, GraphQL, Mapper, and CMS all read collection/field/relation metadata from ON\Data.

## Sole registry

| Concern | Location |
|---------|----------|
| Definition API | `guilhermeaiolfi/overnight-data` → `ON\Data\Definition\*` |
| Framework wiring | `ON\DataIntegration\DataExtension` |
| Container binding | `Registry::class` → `DefinitionRegistryProvider` |
| Cycle schema compile | `ON\ORM\Compiler\CycleRegistryGenerator` |

There is no parallel ORM definition registry and no adapter layer between ON\Data and consumers.

## Registration

Modules and extensions register collections by listening to the init event:

```php
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

final class BlogDefinitionsExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        $init->on(DataDefinitionConfigureEvent::class, function (DataDefinitionConfigureEvent $event): void {
            $event->registry
                ->collection('post')
                ->primaryKey('id')
                ->field('id', 'int')->autoIncrement(true)->end()
                ->field('title', 'string')->validation('required|max:255')->end()
                ->end();
        });
    }
}
```

- Event class: `ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent`
- Payload: public `Registry $registry`
- Do **not** use deleted APIs: `OrmConfigureEvent`, `ON\ORM\Container\RegistryFactory`, or `ON\ORM\Definition\*`

Primary keys are declared at **collection** level: `->primaryKey('id')` or `->primaryKey('tenant_id', 'slug')`. Field-level `primaryKey(true)` is not part of the ON\Data API.

## Cold / warm definition cache

`DefinitionRegistryProvider` resolves `ON\Data\Definition\Registry` as follows:

1. **Warm (cache hit)** — if `data-definitions.php` exists, load the exported array and construct `new Registry($definitions)`.
2. **Cold (cache miss)** — create an empty `Registry`, emit `DataDefinitionConfigureEvent`, then write `$registry->all()` to the cache file and return a fresh `Registry` from that snapshot.

Default cache path: `{project}/var/cache/data-definitions.php` (overridable via DataExtension options `cache_file` / `cache_path`).

CLI helpers:

- `definitions:warmup` — force a cold build and write the cache
- `definitions:clear` — delete the cache file
- Cache clearer name `data-definitions` (via `CacheClearersConfigureEvent`)

## Cycle schema wiring

```
DataExtension binds Registry
        ↓
DefinitionRegistryProvider (cold/warm)
        ↓
CycleDatabaseFactory / CycleRegistryGeneratorFactory
        ↓
CycleRegistryGenerator(Registry) → Cycle\Schema\Compiler
```

- Generator: `ON\ORM\Compiler\CycleRegistryGenerator`
- Factory: `ON\ORM\Container\CycleRegistryGeneratorFactory`
- Consumes `ON\Data\Definition\Registry` and Mapper `ConversionGateway` for field-type resolution

### Generated-field semantics

`CycleRegistryGenerator` marks a field as Cycle `GeneratedField::ON_INSERT` only when:

- the field `isAutoIncrement()`, **or**
- the resolved Cycle type is one of: `primary`, `bigprimary`, `serial`, `bigserial`, `smallserial`

It does **not** mark every primary-key field as generated. A plain `int` (or other non-serial) PK is primary but not insert-generated unless `autoIncrement(true)` is set.

### FirstOfMany

`FirstOfManyRelation` is **unsupported** at the Cycle schema layer. Compiling one throws `UnsupportedDefinitionFeatureException`.

RestApi may still model first-of-many behavior at the **query / handler** layer (`FirstOfManyHandler`, ordering, single cardinality). That is a runtime concern outside Cycle schema compilation — do not approximate FirstOfMany as `hasMany` in the generator.

## RestApi and ONData queries

RestApi list/get/aggregate flows now build ONData **`SelectQuery`** objects directly. Collection, field, and relation metadata also come from ON\Data (`Registry` / `CollectionInterface`).

Primary-key helpers are RestApi-local value objects over ON\Data collections:

| Class | Role |
|-------|------|
| `ON\RestApi\Support\PrimaryKey` | Field/column names, extract from input/row, composite detection |
| `ON\RestApi\Support\PrimaryKeyValue` | Concrete identity values for a collection |
| `ON\RestApi\Support\PrimaryKeyCriteria` | Build concrete identity criteria for mutation writes |

These are not part of the ON\Data package.

## Related docs

- [ORM Entity Definition](orm-entity-definition.md) — fluent ON\Data API
- [Mapper](mapper.md) — Storage / PHP / Wire conversion
