# Mapper

Unified conversion and structural mapping for Overnight. Tempest-inspired `map()->to()` entrypoint, with `PhpRepresentation` as the conversion hub between encodings.

See also [ORM Entity Definition — Field Type Handlers and Cycle Schema](orm-entity-definition.md#field-type-handlers-and-cycle-schema) for how `->type()` connects to Cycle schema compilation.

---

## Installation

Load `MapperExtension` in your application (required by RestApi for row serialization):

```php
use ON\Mapper\MapperExtension;

MapperExtension::install($app);
```

The extension registers `ConversionGateway` in the container and applies `MapperConfig` registrations to the shared gateway used by `map()`.

---

## Representations


| Class                          | Role                                                  |
| ------------------------------ | ----------------------------------------------------- |
| `StorageRepresentation::class` | DB column values (`'2025-01-10 10:00:00'`, `'draft'`) |
| `PhpRepresentation::class`     | Canonical internal types (`DateTimeImmutable`, enums) |
| `WireRepresentation::class`    | JSON-safe REST payloads (ISO-8601 strings, etc.)      |


Same representation → value unchanged. No path → `UnsupportedConversionException`. Invalid value → `ConversionException`.

Routing composes through PHP by default:

```
Storage → Wire  ==  Storage → PHP → Wire
```

---

## Fluent API

```php
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;

// Structural: array → DTO (no value conversion needed)
$user = map($requestData)->to(UserDto::class);

// Wire JSON → typed DTO
$user = map($wirePayload, WireRepresentation::class)->to(UserDto::class);

// Optional per-call override of the source encoding
$user = map($payload)->to(UserDto::class, WireRepresentation::class);

// Wire-property DTO (properties stay wire-encoded)
$input = map($wirePayload)->from(WireRepresentation::class)->as(WireRepresentation::class)->to(InputDto::class);

// List of DTOs
$users = map($rows)->collection()->to(UserDto::class);

// DTO → array (PHP keys, raw property values)
$array = map($dto)->toArray();

// DTO → wire-safe array
$wire = map($dto)->as(WireRepresentation::class)->toArray();

// ORM collection row: Storage → PHP
$row = map($dbRow)
    ->using(CollectionRowMapper::class, $collection)
    ->from(StorageRepresentation::class)
    ->as(PhpRepresentation::class)
    ->toArray();

// ORM collection row: PHP → Wire (REST response)
$wireRow = map($phpRow)
    ->using(CollectionRowMapper::class, $collection)
    ->from(PhpRepresentation::class)
    ->as(WireRepresentation::class)
    ->toArray();

// Bulk ORM rows
$wireRows = map($phpRows)
    ->using(CollectionRowMapper::class, $collection)
    ->from(PhpRepresentation::class)
    ->as(WireRepresentation::class)
    ->collection()
    ->toArray();
```


| Method                   | Role                                          |
| ------------------------ | --------------------------------------------- |
| `from(Rep)`              | Source value encoding                         |
| `as(Rep)`                | Target value encoding after field conversion  |
| `to(Class)`              | Structural target (DTO, `MutationSpec`, etc.) |
| `toArray()` / `toJson()` | Terminal array/JSON output                    |


`to()` is reserved for structural targets only. Passing a representation class to `to()` throws — use `as()` instead.

Omit representation hints when values are already in the target shape or when no field-level conversion applies.

---

## Gateway API (field / row level)

```php
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

$gateway = ConversionGateway::createDefault();

// Field-level
$gateway->map($fieldContext)->to(StorageRepresentation::class, $value, PhpRepresentation::class);
$gateway->to(WireRepresentation::class, $dateTime, PhpRepresentation::class, $fieldContext);

// Row-level
map($dbRow)
    ->using(CollectionRowMapper::class, $collection)
    ->from(StorageRepresentation::class)
    ->as(PhpRepresentation::class)
    ->toArray();
```

---

## Field type resolution

For ORM fields and DTO properties with a known type:

1. Class implements `FieldTypeInterface` → use directly
2. Registered external handler → third-party fallback
3. Backed enum → default enum handler
4. Builtin string (`datetime`, `int`, …) → representation dispatch
5. Throw

---

## FieldContext

Per-value metadata passed to field handlers during conversion. Not the same as ORM `FieldInterface` — it carries only what conversion needs.

```php
use ON\Mapper\Field\FieldContext;

// From an ORM field definition
$context = FieldContext::fromField($collection->fields->get('created_at'));

// From a DTO property or ad-hoc mapping
$context = FieldContext::named('starts_at', 'datetime', nullable: true);
```


| Method          | Purpose                                           |
| --------------- | ------------------------------------------------- |
| `getName()`     | Field/property name (error messages)              |
| `getType()`     | ORM type string or PHP class name                 |
| `isNullable()`  | Whether empty/null input is allowed               |
| `getField()`    | Original ORM field when created via `fromField()` |
| `isClassType()` | Whether `getType()` is a loadable class           |


---

## Builtin field handlers

Registered by type name in `FieldTypeRegistry` (also used when resolving ORM builtin types):


| Type key                                                  | Handler                | `storageType()` |
| --------------------------------------------------------- | ---------------------- | --------------- |
| `datetime`, `timestamp`                                   | `DateTimeFieldType`    | `datetime`      |
| `date`                                                    | `DateFieldType`        | `date`          |
| `bool`, `boolean`                                         | `BoolFieldType`        | `bool`          |
| `int`, `integer`, `primary`, `smallprimary`, `bigprimary` | `IntFieldType`         | `int`           |
| `float`, `double`, `decimal`                              | `FloatFieldType`       | `float`         |
| `json`                                                    | `JsonFieldType`        | `json`          |
| `string`                                                  | `StringFieldType`      | `string`        |
| `text`                                                    | `PassthroughFieldType` | `text`          |


Fallback handlers (not keyed by ORM type string):


| Condition                    | Handler               |
| ---------------------------- | --------------------- |
| Backed enum class            | `BackedEnumFieldType` |
| Other PHP class              | `ClassFieldType`      |
| `DateTimeInterface` subclass | `DateTimeFieldType`   |


---

## Structural attributes


| Attribute           | Purpose                                        |
| ------------------- | ---------------------------------------------- |
| `#[MapFrom('key')]` | Input key alias when mapping arrays → objects  |
| `#[MapTo('key')]`   | Output key alias when mapping objects → arrays |
| `#[Hidden]`         | Exclude from `toArray()`                       |


---

## Nested objects and collections

Structural mapping recurses into nested DTO properties using the **property type**. Public properties only (same as Tempest).

```php
use function ON\Mapper\map;

final class AuthorDto
{
    public string $name = '';
    public string $email = '';
}

final class BookDto
{
    public string $title = '';
    public AuthorDto $author;
}

$book = map([
    'title' => 'Timeline Taxi',
    'author' => ['name' => 'Jane', 'email' => 'jane@example.com'],
])->to(BookDto::class);
```

No representation hint is required for nested shape; use `from(WireRepresentation::class)` (or similar) when nested **scalars** need wire/storage conversion.

### Key aliases (`user` → `author`)

```php
use ON\Mapper\Attribute\MapFrom;
use ON\Mapper\Attribute\MapTo;

final class BookDto
{
    public string $title = '';

    #[MapFrom('user')]
    #[MapTo('user')]
    public AuthorDto $author;
}

$book = map([
    'title' => 'Timeline Taxi',
    'user' => ['name' => 'Jane', 'email' => 'jane@example.com'],
])->to(BookDto::class);
```

### Dot notation (forms / query strings)

Flat keys are expanded before mapping (also applied to PSR request bodies via `PsrRequestToObjectMapper`):

```php
$book = map([
    'author.name' => 'Jane',
    'author.email' => 'jane@example.com',
])->to(BookDto::class);

// With MapFrom('user') on $author:
$book = map(['user.name' => 'Jane', 'user.email' => 'jane@example.com'])->to(BookDto::class);
```

### Lists of nested DTOs

Declare the element type in PHPDoc:

```php
final class BookWithChaptersDto
{
    public string $title = '';

    /** @var list<ChapterDto> */
    public array $chapters = [];
}
```

### Parent back-references

When a nested object declares a property typed as the **parent class** (or `/** @var Parent[] */`), the mapper wires it after mapping:

```php
final class BookDto
{
    public string $title = '';
    public ChapterDto $chapter;
}

final class ChapterDto
{
    public string $name = '';
    public BookDto $parent;
    /** @var BookDto[] */
    public array $parentCollection = [];
}
```

### Custom nested encoding

- **Default:** property type drives nested `map($data)->to(ChildDto::class)`.
- **Value encoding:** `MapperConfig::register(ChildDto::class, ChildFieldType::class)` for non-standard wire/storage shapes on nested scalars.
- **Value objects:** `ClassFieldType` (`fromStorage`, `fromString`) — not for arbitrary nested DTO trees.

### `stdClass` and representations

Structural mappers honor `from()` / `as()` via `MappingContext`. For `stdClass`, field types are **not** guessed — pass an explicit blueprint with `blueprint()`:

```php
use ON\Mapper\Blueprint\MappingBlueprint;
use ON\Mapper\Representation\WireRepresentation;

$blueprint = MappingBlueprint::fromArray([
    'meta' => ['created_at' => 'datetime'],
]);

$object = map(['meta' => ['created_at' => '2024-03-15T10:30:00+00:00']], WireRepresentation::class)
    ->blueprint($blueprint)
    ->to(\stdClass::class);

$wire = map($object)->blueprint($blueprint)->as(WireRepresentation::class)->toArray();
```

From a shape class (public properties + optional `#[MapField]`):

```php
use ON\Mapper\Attribute\MapField;

final class PayloadShape
{
    public MetaShape $meta;
}

final class MetaShape
{
    #[MapField('datetime')]
    public string $created_at;
}

$blueprint = MappingBlueprint::fromClass(PayloadShape::class);
```

Per-field structural mapper override (optional):

```php
use ON\Mapper\Blueprint\FieldBlueprintEntry;

MappingBlueprint::fromArray([
    'rows' => new FieldBlueprintEntry(
        RowDto::class,
        \ON\Mapper\Structural\ArrayToObjectMapper::class,
    ),
]);
```

Typed DTOs continue to use property types and `#[MapFrom]`; blueprints are for untyped `stdClass` (and future overrides).

---

## What replaces what


| Previous                          | Current                                                                           |
| --------------------------------- | --------------------------------------------------------------------------------- |
| `TypecastRegistry` + `*Typecast`  | `FieldTypeRegistry` + `FieldTypeInterface` handlers                               |
| `CollectionTypecast`              | `map($row)->using(CollectionRowMapper::class, $c)->from(...)->as(...)->toArray()` |
| `CollectionSerializer`            | `map(...)->using(CollectionRowMapper::class, $c)->from(Wire)->as(Php)->toArray()` |
| `Context` enum + `*Context` hints | `*Representation::class`                                                          |


---

## Extension registration

Load `MapperExtension` and register custom field handlers during `ConfigConfigureEvent`:

```php
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Mapper\MapperConfig;

$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
    $event->config->get(MapperConfig::class)
        ->register('file', FileFieldType::class)
        ->register(StatusEnum::class, StatusEnumFieldType::class);
});
```

Field handlers always need an explicit type key. Do not infer the key from `storageType()` — that describes DB encoding (`'string'`, `'int'`) and would overwrite builtins like `StringFieldType`.

Registrations are applied to the shared `ConversionGateway` used by `map()` automatically — no need to pass a gateway argument.

For tests or scripts without a bootstrapped container, configure the gateway directly:

```php
ConversionGateway::configure(
    (new MapperConfig())->register('file', FileFieldType::class)
);
```

---

## Custom field handlers

Implement `FieldTypeInterface` for types that need custom conversion between representations:

```php
use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class StatusEnumFieldType implements FieldTypeInterface
{
    public static function storageType(): string
    {
        return 'string'; // Cycle column type
    }

    public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($from) {
            PhpRepresentation::class => $value instanceof StatusEnum ? $value : StatusEnum::from($value),
            StorageRepresentation::class, WireRepresentation::class => StatusEnum::from((string) $value),
            default => throw UnsupportedConversionException::forRepresentation($from),
        };
    }

    public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
    {
        if ($value === null) {
            return null;
        }

        $enum = $value instanceof StatusEnum ? $value : StatusEnum::from($value);

        return match ($to) {
            PhpRepresentation::class => $enum,
            StorageRepresentation::class => $enum->value,
            WireRepresentation::class => $enum->value,
            default => throw UnsupportedConversionException::forRepresentation($to),
        };
    }
}
```

Register with the PHP class or ORM type key that should resolve to the handler:

```php
->register(StatusEnum::class, StatusEnumFieldType::class)
->register('file', FileFieldType::class)
```

Optional: reference the handler class directly in ORM `->type()` when you want Cycle schema to use `storageType()`:

```php
->field('starts_at', 'datetime')->type(DateTimeFieldType::class)->end()
```

---

## Implementation status

- Core: `ConversionGateway`, `PhpRepresentation`, `StorageRepresentation`, `WireRepresentation`
- Fluent: `map()`, `MapBuilder`, structural mappers (nested DTOs, dot notation, parent wiring)
- ORM: `CycleRegistryGenerator` maps `FieldTypeInterface::storageType()` and validates Cycle column types
- RestApi: `ItemRepository`, `DirectusMutationBuilder`, and Directus actions use `map()` via `ConversionGateway` (requires `MapperExtension`). Action `$options` use `input` / `output` representation class constants; each action defines an intermediate representation (Php for all Directus actions today).
- Pending: GraphQL/cache representations, optional direct edge converters

