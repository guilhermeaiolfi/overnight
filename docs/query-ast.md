# Query AST Specification

Overnight's REST API currently accepts Directus-style query parameters. That public syntax is useful and should remain supported, but it should not be the internal query model. The framework should parse request-specific query languages into a stable query AST, then let resolvers, loaders, and SQL compilers consume the AST.

This keeps the working Directus-style API while making space for future query languages without rewriting database loading behavior.

## Goals

- Keep Directus-style REST query parameters as the default public API.
- Define one stable internal query model for list, item, relation, aggregate, and grouped queries.
- Allow multiple parsers to produce the same AST.
- Keep loaders focused on loading rows and relations, not parsing user syntax.
- Keep SQL generation behind expression compilers, not embedded in AST nodes.
- Support function fields such as `year(created_at)` in filters, sorting, grouping, and aggregate inputs.
- Support query aliases by separating public response names from real ORM field or relation names.

## Non-Goals

- Do not expose arbitrary SQL fragments.
- Do not require GraphQL support in the first version.
- Do not replace the current REST syntax before an equivalent parser exists.
- Do not make relation loaders responsible for understanding query languages.
- Do not require raw computed field projection in the first version.

## Pipeline

```text
Request syntax
    Directus-style params, future compact query language, etc.
        |
        v
Query parser
    Validates syntax and creates AST nodes
        |
        v
Query normalizer
    Expands defaults, verifies fields/relations, adds internal relation keys
        |
        v
Resolver / loaders
    Build root and relation queries from QuerySpec
        |
        v
Expression compiler
    Converts expression AST to database-specific SQL expressions
```

## Top-Level Nodes

### QuerySpec

Represents a complete read query for one collection.

```php
final class QuerySpec
{
    public function __construct(
        public readonly string $collection,
        public readonly SelectionSet $selection,
        public readonly ?FilterNode $filter = null,
        public readonly ?SearchField $search = null,
        public readonly array $sort = [],
        public readonly ?PaginationSpec $pagination = null,
        public readonly array $aggregate = [],
        public readonly array $groupBy = [],
        public readonly array $meta = [],
    ) {}
}
```

Relationships:

- `selection` controls which entity fields and relations are returned.
- `filter` limits root rows before pagination or aggregation.
- `search` applies text search across searchable fields.
- `sort` orders root rows, and can contain field or function expressions.
- `pagination` applies to normal list reads.
- `aggregate` and `groupBy` change the query result shape from entity rows to aggregate rows.
- `meta` requests side-channel counts such as `total_count` and `filter_count`.

### SelectionSet

Represents requested fields and relations for an entity-shaped result.

```php
final class SelectionSet
{
    /** @param list<SelectionNode> $nodes */
    public function __construct(
        public readonly array $nodes = [],
        public readonly bool $explicit = false,
    ) {}
}
```

Relationships:

- A root `QuerySpec` owns one root `SelectionSet`.
- A `RelationSelection` owns a `RelationQuerySpec`, which owns the nested `SelectionSet`.
- Normalizers can add internal fields required for relation loading without marking them as client-requested.

### SelectionNode

Base concept for things that can appear in a selection set.

```php
interface SelectionNode
{
    public function responseName(): string;
}
```

Concrete selection nodes:

- `FieldSelection`
- `ComputedFieldSelection`
- `RelationSelection`
- `RelationAggregateSelection`
- `WildcardSelection`

## Selection Nodes

### FieldSelection

Represents a real ORM field.

```php
final class FieldSelection implements SelectionNode
{
    public function __construct(
        public readonly FieldExpression $field,
        public readonly string $responseName,
        public readonly ?string $alias = null,
        public readonly bool $internal = false,
    ) {}
}
```

Notes:

- `field` references the ORM field name, not necessarily the database column.
- `responseName` is the public response key.
- `alias` can preserve parser-level alias information where useful.
- `internal=true` means the field is required for loading but should not be returned unless explicitly requested.

### ComputedFieldSelection

Represents a safe expression projected into an entity-shaped result.

```php
final class ComputedFieldSelection implements SelectionNode
{
    public function __construct(
        public readonly ExpressionNode $expression,
        public readonly string $responseName,
        public readonly bool $declared = false,
    ) {}
}
```

Notes:

- Raw computed projections should be conservative.
- Prefer `declared=true` for computed fields defined by collection metadata.
- A parser may reject raw `fields[]=year(created_at)` until a public projection policy is chosen.

### WildcardSelection

Represents all visible fields in the current collection scope.

```php
final class WildcardSelection implements SelectionNode
{
    public function __construct(
        public readonly bool $visibleOnly = true,
    ) {}
}
```

Notes:

- The normalizer expands this to concrete `FieldSelection` nodes.
- Hidden fields remain excluded unless a future explicit policy allows them.
- The CMS query language's `*` virtual node maps naturally to this selection.

### RelationSelection

Represents an entity-shaped relation and its nested selection.

```php
final class RelationSelection implements SelectionNode
{
    public function __construct(
        public readonly string $responseName,
        public readonly string $relationName,
        public readonly string $targetCollection,
        public readonly RelationQuerySpec $query,
        public readonly ?RelationLoadHint $loadHint = null,
    ) {}
}
```

Relationships:

- `responseName` is the public response key.
- `relationName` is the real ORM relation name on the source collection.
- `targetCollection` is resolved from ORM metadata during normalization.
- `query` stores relation-level selection, filters, sort, and pagination.
- `loadHint` is optional and can carry parser-level relation loading preferences without changing query semantics.
- Directus `alias[published_posts]=posts` becomes `RelationSelection(responseName: published_posts, relationName: posts, ...)`.
- Multiple relation selections can point at the same `relationName` as long as their `responseName` values are distinct.

### RelationLoadHint

Represents an optional relation loading preference. It is a hint, not a semantic requirement.

```php
enum RelationLoadHint
{
    case InLoad;
    case PostLoad;
    case Join;
    case LeftJoin;
}
```

Notes:

- Directus-style REST does not need to expose this.
- The CMS query language can map relation modifiers to this enum.
- Resolvers may ignore hints when a backend cannot honor them safely.

### RelationAggregateSelection

Represents an aggregate-shaped relation. This is separate from `RelationSelection` because grouped and aggregate relation output does not return normal related entity rows.

```php
final class RelationAggregateSelection implements SelectionNode
{
    public function __construct(
        public readonly string $responseName,
        public readonly string $relationName,
        public readonly string $targetCollection,
        public readonly RelationAggregateQuerySpec $query,
    ) {}
}
```

Relationships:

- `responseName` is the public response key.
- `relationName` is the real ORM relation name on the source collection.
- `targetCollection` is resolved from ORM metadata during normalization.
- `query` stores relation-level filter, group, aggregate, sort, and pagination options for aggregate rows.
- Directus alias input can map multiple aggregate relation views to the same real relation.

### RelationQuerySpec

Represents query options scoped to an entity-shaped relation load.

```php
final class RelationQuerySpec
{
    public function __construct(
        public readonly SelectionSet $selection,
        public readonly ?FilterNode $filter = null,
        public readonly ?SearchField $search = null,
        public readonly array $sort = [],
        public readonly ?PaginationSpec $pagination = null,
    ) {}
}
```

Notes:

- This is the AST representation of relation-scoped behavior.
- Directus-style `deep[relation][_filter]`, `_sort`, `_limit`, and `_offset` are parser input only.
- Nested Directus `deep` entries should be scattered into the corresponding nested `RelationSelection` nodes.

### RelationAggregateQuerySpec

Represents query options scoped to an aggregate-shaped relation load.

```php
final class RelationAggregateQuerySpec
{
    public function __construct(
        public readonly ?FilterNode $filter = null,
        public readonly ?SearchField $search = null,
        public readonly array $groupBy = [],
        public readonly array $aggregate = [],
        public readonly array $sort = [],
        public readonly ?PaginationSpec $pagination = null,
    ) {}
}
```

Notes:

- `groupBy` contains `GroupBySpec` nodes.
- `aggregate` contains `AggregateSpec` nodes.
- `sort` orders aggregate rows, not entity rows.
- Relation aggregate support may be implemented after root aggregate support, but the AST keeps the shape explicit now.

## Filter Nodes

Filters are trees. They compare expressions to values and combine conditions with logical nodes.

```php
interface FilterNode
{
}
```

Concrete filter nodes:

- `SearchField`
- `ComparisonFilter`
- `NullFilter`
- `EmptyFilter`
- `BetweenFilter`
- `SetFilter`
- `LogicalFilter`
- `RelationExistsFilter`

### SearchField

Represents free-text search across searchable fields in the current collection scope.

```php
final class SearchField
{
    public function __construct(
        public readonly string $term,
        public readonly array $fields = [],
    ) {}
}
```

Notes:

- `fields` is optional. When empty, the normalizer resolves searchable fields from collection metadata.
- Search is modeled separately from normal filters because it expands to multiple field comparisons and may later use backend-specific full-text features.
- Root search and relation-scoped search use the same node.

### ComparisonFilter

```php
final class ComparisonFilter implements FilterNode
{
    public function __construct(
        public readonly ExpressionNode $left,
        public readonly ComparisonOperator $operator,
        public readonly ValueNode $right,
    ) {}
}
```

Operators:

- `eq`
- `neq`
- `lt`
- `lte`
- `gt`
- `gte`
- `contains`
- `ncontains`
- `startsWith`
- `endsWith`

### SetFilter

```php
final class SetFilter implements FilterNode
{
    /** @param list<ValueNode> $values */
    public function __construct(
        public readonly ExpressionNode $left,
        public readonly SetOperator $operator,
        public readonly array $values,
    ) {}
}
```

Operators:

- `in`
- `notIn`

### BetweenFilter

```php
final class BetweenFilter implements FilterNode
{
    public function __construct(
        public readonly ExpressionNode $left,
        public readonly ValueNode $from,
        public readonly ValueNode $to,
        public readonly bool $negated = false,
    ) {}
}
```

### NullFilter

```php
final class NullFilter implements FilterNode
{
    public function __construct(
        public readonly ExpressionNode $left,
        public readonly bool $negated = false,
    ) {}
}
```

### EmptyFilter

```php
final class EmptyFilter implements FilterNode
{
    public function __construct(
        public readonly ExpressionNode $left,
        public readonly bool $negated = false,
    ) {}
}
```

### LogicalFilter

```php
final class LogicalFilter implements FilterNode
{
    /** @param list<FilterNode> $children */
    public function __construct(
        public readonly LogicalOperator $operator,
        public readonly array $children,
        public readonly bool $negated = false,
    ) {}
}
```

Operators:

- `and`
- `or`

### RelationExistsFilter

Represents a relation-level filter as an `EXISTS` query.

```php
final class RelationExistsFilter implements FilterNode
{
    public function __construct(
        public readonly string $relation,
        public readonly string $targetCollection,
        public readonly FilterNode $filter,
    ) {}
}
```

Relationships:

- The parser can produce this from nested Directus filters such as `filter[author][name][_eq]=Jane`.
- The normalizer resolves relation metadata and target collection.
- The SQL compiler decides whether the relation is direct or junction-backed.

## Expression Nodes

Expressions describe values that can appear in filters, sorting, grouping, aggregate inputs, or computed projections.

```php
interface ExpressionNode
{
}
```

Concrete expression nodes:

- `FieldExpression`
- `FunctionExpression`
- `AggregateExpression`

### FieldExpression

```php
final class FieldExpression implements ExpressionNode
{
    public function __construct(
        public readonly string $field,
    ) {}
}
```

Notes:

- The field name is an ORM field name.
- Column names are resolved later by the SQL expression compiler.

### FunctionExpression

```php
final class FunctionExpression implements ExpressionNode
{
    /** @param list<ExpressionNode|ValueNode> $arguments */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
    ) {}
}
```

Supported first-version functions:

- `year(field)`
- `month(field)`
- `day(field)`
- `hour(field)`
- `date(field)`

Notes:

- Function support is allowlisted.
- Parser syntax may look like `year(created_at)`, but the AST should not store the original string as the source of truth.
- The SQL compiler handles database differences such as SQLite `strftime`, PostgreSQL `EXTRACT`, and MySQL `YEAR`.

### AggregateExpression

```php
final class AggregateExpression implements ExpressionNode
{
    public function __construct(
        public readonly AggregateFunction $function,
        public readonly ExpressionNode|WildcardExpression $argument,
        public readonly ?string $alias = null,
        public readonly bool $distinct = false,
    ) {}
}
```

Supported functions:

- `count`
- `sum`
- `avg`
- `min`
- `max`

Notes:

- Distinct is a property of the aggregate, not a separate function in the AST.
- `countDistinct(user_id)` in Directus-style syntax becomes `AggregateExpression(count, FieldExpression(user_id), distinct: true)`.
- Aggregates change the output shape and should be handled by aggregate query planning, not normal entity row loading.

### WildcardExpression

```php
final class WildcardExpression implements ExpressionNode
{
}
```

Notes:

- Used for `count(*)`.
- Should only be accepted where a wildcard is meaningful.

## Value Nodes

Values are bound parameters or dynamic variables, never raw SQL.

```php
interface ValueNode
{
}
```

Concrete value nodes:

- `LiteralValue`
- `ListValue`
- `DynamicVariableValue`

### LiteralValue

```php
final class LiteralValue implements ValueNode
{
    public function __construct(
        public readonly mixed $value,
    ) {}
}
```

### ListValue

```php
final class ListValue implements ValueNode
{
    /** @param list<ValueNode> $values */
    public function __construct(
        public readonly array $values,
    ) {}
}
```

### DynamicVariableValue

```php
final class DynamicVariableValue implements ValueNode
{
    public function __construct(
        public readonly string $name,
    ) {}
}
```

Notes:

- `$current_user`, `$now`, and `$today` should become dynamic variable nodes during parsing.
- A resolver or normalizer resolves them into literal values using configured dynamic variables.

## Sort, Grouping, Aggregation, and Pagination

### SortSpec

```php
final class SortSpec
{
    public function __construct(
        public readonly ExpressionNode $expression,
        public readonly SortDirection $direction = SortDirection::Asc,
    ) {}
}
```

Examples:

- Directus `sort=-created_at` becomes `SortSpec(FieldExpression(created_at), Desc)`.
- Directus `sort=-month(created_at)` becomes `SortSpec(FunctionExpression(month, [FieldExpression(created_at)]), Desc)`.

### GroupBySpec

```php
final class GroupBySpec
{
    public function __construct(
        public readonly ExpressionNode $expression,
        public readonly ?string $alias = null,
    ) {}
}
```

Notes:

- `groupBy[]=year(created_at)` becomes `GroupBySpec(FunctionExpression(year, [FieldExpression(created_at)]), "year_created_at")`.
- Response formatting can map the alias back to the original public key when needed.

### AggregateSpec

```php
final class AggregateSpec
{
    public function __construct(
        public readonly AggregateExpression $expression,
        public readonly string $responseFunction,
        public readonly string $responseField,
    ) {}
}
```

Notes:

- `responseFunction` and `responseField` preserve the current REST response shape.
- For example, `aggregate[countDistinct]=user_id` can still return `["countDistinct" => ["user_id" => 2]]` while the internal aggregate is `count(distinct user_id)`.

### PaginationSpec

```php
final class PaginationSpec
{
    public function __construct(
        public readonly int $limit,
        public readonly int $offset = 0,
    ) {}
}
```

Notes:

- Page-based input is normalized into limit and offset.
- Max limits are enforced by the normalizer.

## Parser Responsibilities

Parsers translate public syntax into AST nodes.

```php
interface QueryParserInterface
{
    public function parse(string $collection, array $input): QuerySpec;
}
```

The first parser should be `DirectusQueryParser`.

Responsibilities:

- Parse `fields`, `filter`, `sort`, `limit`, `offset`, `page`, `meta`, `aggregate`, `groupBy`, and `deep`.
- Convert function strings such as `year(created_at)` into `FunctionExpression`.
- Convert aggregate names such as `countDistinct` into `AggregateExpression` with `distinct=true`.
- Convert Directus aliases such as `alias[published_posts]=posts` into relation selections with separate response and relation names.
- Resolve aliases within the collection scope where they are declared, including nested relation scopes.
- Move Directus `deep` options onto the matching nested `RelationQuerySpec` nodes.
- Preserve current Directus-style response names.
- Reject unsupported syntax before query execution.

Future parsers can produce the same AST from different syntaxes.

Examples:

- A compact CMS-like field query parser.
- A JSON body query parser.
- A named saved-query parser.

## CMS Query Language Compatibility

The older CMS parser is a useful second syntax to validate the AST. Its node tree maps cleanly to the same query model.

### CMS Root and Fields

```text
post{id,title}
```

Becomes:

```text
QuerySpec(collection: post)
  SelectionSet
    FieldSelection(id)
    FieldSelection(title)
```

### CMS Nested Relations

```text
post{id,title,author{name}}
```

Becomes:

```text
QuerySpec(collection: post)
  SelectionSet
    FieldSelection(id)
    FieldSelection(title)
    RelationSelection(
      responseName: author,
      relationName: author
    )
      RelationQuerySpec
        selection:
          FieldSelection(name)
```

### CMS Shallow Dotted Relations

```text
post{id,author.name}
```

Becomes the same AST as the nested relation example. The parser decides that dotted syntax is just shorthand for a nested `RelationSelection`.

### CMS Wildcard

```text
post{*}
```

Becomes:

```text
QuerySpec(collection: post)
  SelectionSet
    WildcardSelection(visibleOnly: true)
```

The normalizer expands `WildcardSelection` into visible `FieldSelection` nodes for the target collection.

### CMS Relation Modifiers

The CMS parser supports relation modifiers that map to Cycle relation loading methods.

```text
post{id,!comments{body},~author{name}}
```

Becomes:

```text
RelationSelection(
  responseName: comments,
  relationName: comments,
  loadHint: Join
)
  RelationQuerySpec
    selection:
      FieldSelection(body)

RelationSelection(
  responseName: author,
  relationName: author,
  loadHint: LeftJoin
)
  RelationQuerySpec
    selection:
      FieldSelection(name)
```

Modifier mapping:

| CMS Modifier | Load Hint |
|--------------|-----------|
| `%` | `InLoad` |
| `:` | `PostLoad` |
| `!` | `Join` |
| `~` | `LeftJoin` |

Notes:

- Load hints are optional execution preferences, not AST semantics.
- A SQL REST resolver may ignore a load hint if relation filters, pagination, or backend limitations make it unsafe.

### CMS Normalizers

Existing CMS normalizers map to AST normalizer responsibilities:

| CMS Normalizer | AST Equivalent |
|----------------|----------------|
| `VerifyNamesNormalizer` | Verify root collection, fields, relations, and target collections |
| `IncludeColumnsNormalizer` | Add internal key `FieldSelection` nodes required for relation loading |
| `MergeRelationsNormalizer` | Merge duplicate relation selections when response name, relation name, and query specs are compatible |
| `UpdateRelationNormalizer` | Convert relation modifiers into `RelationLoadHint` values |

### Compatibility Assessment

The current AST is enough to represent the CMS query language's root selection, nested selection, dotted shallow relations, wildcard virtual fields, and relation loading modifiers.

CMS filters are a separate older parser and should map into the same filter AST used by Directus-style filters if revived.

## Normalizer Responsibilities

Normalizers run after parsing and before resolver execution.

```php
interface QueryNormalizerInterface
{
    public function normalize(QuerySpec $query): QuerySpec;
}
```

Responsibilities:

- Verify root collection exists.
- Verify fields exist on the correct collection.
- Verify relations and target collections.
- Add default visible fields when the selection is not explicit.
- Add internal relation key fields required by loaders.
- Resolve relation aliases to real relation names.
- Enforce max limit.
- Resolve dynamic variables using configured variables.
- Resolve `SearchField` nodes to searchable collection fields when no explicit search field list is provided.
- Reject invalid aggregate/grouping combinations.

## Compiler Responsibilities

Compilers convert AST nodes into backend-specific query expressions.

```php
interface ExpressionCompilerInterface
{
    public function compile(ExpressionNode $expression, CollectionInterface $collection, string $tableAlias): FragmentInterface;
}
```

SQL compiler responsibilities:

- Map ORM fields to database columns.
- Quote identifiers with the active database driver.
- Compile allowlisted functions per database dialect.
- Compile aggregate expressions and aliases.
- Produce parameterized SQL for values.

The AST must not contain SQL snippets.

## Resolver and Loader Responsibilities

Resolvers consume normalized `QuerySpec` objects.

Root resolver responsibilities:

- Decide whether the query is an entity-list query or aggregate query.
- Apply root filters, search, sort, pagination, grouping, and aggregation.
- Create the root select query.
- Pass relation selections and relation query specs to loaders.

Loader responsibilities:

- Load selected relations.
- Apply already-normalized relation filters, sorting, and pagination.
- Treat aliased relations as independent loads when their query specs differ.
- Route aggregate-shaped relation selections through aggregate relation planning rather than normal entity hydration.
- Include internal keys needed to connect rows.
- Clean internal fields from responses unless explicitly requested.

Loaders may use the expression compiler, but they should not parse request syntax.

## Directus-Style Mapping

### Fields

```text
fields[]=id
fields[]=title
fields[]=author.name
```

Becomes:

```text
SelectionSet
  FieldSelection(id)
  FieldSelection(title)
  RelationSelection(author)
    SelectionSet
      FieldSelection(name)
```

### Relation Alias

```text
fields[]=published_posts.title,recent_posts.title
alias[published_posts]=posts
alias[recent_posts]=posts
deep[published_posts][_filter][status][_eq]=published
deep[recent_posts][_sort]=-created_at
deep[recent_posts][_limit]=3
```

Becomes:

```text
RelationSelection(
  responseName: published_posts,
  relationName: posts
)
  RelationQuerySpec
    selection:
      FieldSelection(title)
    filter:
      ComparisonFilter(FieldExpression(status), eq, LiteralValue(published))

RelationSelection(
  responseName: recent_posts,
  relationName: posts
)
  RelationQuerySpec
    selection:
      FieldSelection(title)
    sort:
      SortSpec(FieldExpression(created_at), Desc)
    pagination:
      PaginationSpec(limit: 3)
```

Notes:

- Directus aliases are parser input only.
- The AST keeps both names because loaders need the real relation name while response formatting needs the public response name.
- Alias names must be simple identifiers.
- An alias cannot overwrite a real field or relation in the collection scope where it is declared.
- Nested aliases are allowed when scoped to a specific relation path.
- Aliased relation selections are independent. Two aliases can target the same ORM relation and still have different filters, sorting, pagination, and nested selections.

### Nested Relation Alias

Nested aliases use the relation path as their scope.

```text
fields[]=authors.published_posts.title
alias[authors.published_posts]=posts
deep[authors][published_posts][_filter][status][_eq]=published
```

Becomes:

```text
RelationSelection(
  responseName: authors,
  relationName: authors
)
  RelationQuerySpec
    selection:
      RelationSelection(
        responseName: published_posts,
        relationName: posts
      )
        RelationQuerySpec
          selection:
            FieldSelection(title)
          filter:
            ComparisonFilter(FieldExpression(status), eq, LiteralValue(published))
```

Notes:

- The alias key `authors.published_posts` means `published_posts` is an alias inside the `authors` relation scope.
- The same alias name may be reused in different relation scopes if it does not collide within that scope.
- Directus-style parser support for nested aliases can be added without changing the AST shape.

### Relation Aggregate Alias

Relation aggregate output is modeled separately from normal relation output.

```text
alias[posts_by_year]=posts
deep[posts_by_year][_aggregate][count]=id
deep[posts_by_year][_groupBy][]=year(created_at)
```

Becomes:

```text
RelationAggregateSelection(
  responseName: posts_by_year,
  relationName: posts
)
  RelationAggregateQuerySpec
    groupBy:
      GroupBySpec(FunctionExpression(year, [FieldExpression(created_at)]), alias: year_created_at)
    aggregate:
      AggregateSpec(AggregateExpression(count, FieldExpression(id)), responseFunction: count, responseField: id)
```

Notes:

- This is a proposed AST shape, not necessarily a first implementation requirement.
- It avoids making normal `RelationSelection` sometimes return entities and sometimes return aggregate rows.

### Filter

```text
filter[year(created_at)][_eq]=2026
```

Becomes:

```text
ComparisonFilter(
  FunctionExpression(year, [FieldExpression(created_at)]),
  eq,
  LiteralValue(2026)
)
```

### Relation Filter

```text
filter[author][name][_eq]=Jane
```

Becomes:

```text
RelationExistsFilter(
  relation: author,
  filter: ComparisonFilter(FieldExpression(name), eq, LiteralValue(Jane))
)
```

### Sort

```text
sort=-month(created_at),title
```

Becomes:

```text
SortSpec(FunctionExpression(month, [FieldExpression(created_at)]), Desc)
SortSpec(FieldExpression(title), Asc)
```

### Aggregate

```text
aggregate[countDistinct]=user_id
```

Becomes:

```text
AggregateSpec(
  AggregateExpression(count, FieldExpression(user_id), distinct: true),
  responseFunction: countDistinct,
  responseField: user_id
)
```

### Group By

```text
groupBy[]=year(created_at)
```

Becomes:

```text
GroupBySpec(
  FunctionExpression(year, [FieldExpression(created_at)]),
  alias: year_created_at
)
```

### Deep Relation Query

```text
deep[comments][_filter][status][_eq]=approved
deep[comments][_sort]=-created_at
deep[comments][_limit]=5
```

Becomes:

```text
RelationSelection(comments)
  RelationQuerySpec
    filter: ComparisonFilter(FieldExpression(status), eq, LiteralValue(approved))
    sort: [SortSpec(FieldExpression(created_at), Desc)]
    pagination: PaginationSpec(limit: 5)
```

## First Implementation Slice

The first slice should preserve behavior while moving internals toward the AST.

1. Add AST node classes under `src/RestApi/Query`.
2. Add `DirectusQueryParser` that maps current REST query arrays to `QuerySpec`.
3. Add `SqlExpressionCompiler` by extracting parser-independent compilation from `SqlExpressionBuilder`.
4. Teach `SqlRestResolver::list()` and `aggregate()` to accept or internally create `QuerySpec`.
5. Keep public `RestApiService` and middleware signatures unchanged.
6. Add tests that assert current Directus-style queries produce the expected AST and current SQL behavior still works.

## Decisions

- Raw computed field projection can be added later. Functions are first-class in filters, sorting, grouping, and aggregate inputs.
- Relation aggregate output belongs in the AST now, even if implementation lands after root aggregation.
- Dynamic variables resolve in the normalizer.
- Search is modeled as `SearchField`, not lowered into an `or` filter during parsing.
- Nested aliases are supported by scoping aliases to relation paths.
- First implementation namespace should be `ON\RestApi\Query`. If the model becomes shared by GraphQL or CMS later, it can be promoted to `ON\Query`.

## Open Questions

- Should relation-level aggregation be represented now or deferred until root aggregation is stable?
