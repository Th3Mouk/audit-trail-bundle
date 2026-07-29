# API Platform bridge — maintainer note

Exposes `Th3Mouk\AuditTrail\Entity\AuditLog` as a **read-only, cursor-paginated** API Platform
resource. Nothing here is loaded when `api-platform/core` is absent, and the entity carries no
API Platform attribute, so the bundle boots with or without the package.

`FeedVersionRequirement` refuses to assemble the feed below api-platform/core 4.3.9: a date cursor was
cast to a string when building `hydra:next` until then, so paging was broken outright. It is checked
in the pass rather than declared as a composer `conflict` so that applications which never enable the
feed are not forced to upgrade.

## Installation seam

```php
// Th3Mouk\AuditTrail\AuditTrailBundle::build()
RegisterAuditFeedPass::register($container);
```

`register()` — not a bare `addCompilerPass()` — is the supported entry point. The pass must run at
`TYPE_BEFORE_OPTIMIZATION` priority `10` because API Platform's `FilterPass` snapshots every
`api_platform.filter` service into `api_platform.filter_locator` at priority `0`; registered later,
our filters exist but are never applied.

The pass reads these container parameters and no-ops when they are missing:

| parameter | fallback |
| --- | --- |
| `audit_trail.enabled` | `true` |
| `audit_trail.bridges.api_platform.enabled` | `false` (opt-in) |
| `audit_trail.bridges.api_platform.route_prefix` | `/audit-logs` |
| `audit_trail.bridges.api_platform.items_per_page` | `50` |
| `audit_trail.bridges.api_platform.max_items_per_page` | `200` |
| `audit_trail.bridges.api_platform.security` | resolved from `access` by the extension |

The `enabled` parameter is strictly `true` or nothing: the pass registers no HTTP surface for any
other value. On top of that, environment detection is
`interface_exists(ResourceMetadataCollectionFactoryInterface::class)` plus the
presence of `api_platform.metadata.resource.metadata_collection_factory` and
`api_platform.doctrine.orm.search_filter`, i.e. api-platform installed *and* registered *and* with
Doctrine ORM support.

## Why PHP metadata and not XML/YAML

`src/Resources/config/api_platform/` was considered and dropped. Checked against
`api-platform/core` 4.3 in `vendor/`:

- `AbstractResourceExtractor::resolve()` expands `%container.parameters%` only for the resource
  class name and for uriVariables' from/to classes. `uriTemplate`, `paginationItemsPerPage`,
  `paginationMaximumItemsPerPage` and `security` are never resolved, so a static file cannot carry
  configuration. (`PhpFileResourceExtractor` does resolve every property, but only into `string`
  values — the `?int` pagination properties would raise a `TypeError`.)
- `security` must be **absent**, not empty, when the host configured none. `phpize()` drops the key
  on `isset()`, so `security: null` works — but a file cannot choose between emitting the key and
  omitting it based on configuration.
- `XmlResourceExtractor::buildPaginationViaCursor()` produces `['id' => 'DESC']`, while
  `PartialCollectionViewNormalizer::cursorPaginationFields()` reads `$field['field']` /
  `$field['direction']` from a **list of maps**. Cursor pagination declared in XML silently emits no
  cursor links. YAML passes the array through verbatim and would have worked; PHP is used for the
  reasons above.

`AuditFeedResourceMetadataCollectionFactory` decorates
`api_platform.metadata.resource.metadata_collection_factory` at priority `810`. In Symfony,
the **highest** decoration priority is innermost, so `810` post-processes after `parameter` (1000)
and before `uri_template`/`link` (500), `operation_name`, `formats` and `filters` (200) — those
still enrich our resource. `AuditFeedResourceNameCollectionFactory` puts the class into the name
collection, which is otherwise attribute-driven.

## Operations

| method | URI | notes |
| --- | --- | --- |
| `GET` | `{prefix}{._format}` | keyset-paginated collection, light view |
| `GET` | `{prefix}/{id}{._format}` | detail view |

Writes are **not** declared and **not** hidden behind `NotExposed`. Both URIs exist as GET-only
Symfony routes, so `POST`/`PATCH`/`PUT`/`DELETE` match the path but not the method and the router
answers **405 Method Not Allowed**. A 404 would wrongly suggest the trail does not exist.

There is no nested per-aggregate route. Inline history is the collection filtered by
`?rootType=…&rootId=…`.

## Keyset pagination

```
paginationViaCursor: [['field' => 'id', 'direction' => 'DESC']]
paginationPartial:   true
order:               ['id' => 'DESC']
```

The primary key is a UUID v7: time-ordered, so it is both the sort key and the cursor. Offset
paging would re-scan the prefix of a growing table and drift whenever rows are inserted between two
requests; keyset paging reads a bounded index range and is immune to concurrent inserts.
`paginationPartial: true` also drops the `COUNT(*)`, which is the other cost that grows with the
trail.

**`KeysetCursorFilter` exists because API Platform's `RangeFilter` cannot do this.**
`RangeFilterTrait::normalizeValue()` rejects anything failing `is_numeric()` and logs it away, so
`id[lt]=<uuid>` is silently dropped and every page returns the first one. The bridge's filter
compares the column against a real `Symfony\Component\Uid\Uuid`, bound with the `uuid` Doctrine
type, and supports `gt`, `gte`, `lt`, `lte`.

`CursorTiebreakerExtension` (collection query extension, priority `-33`) appends `id` to the
`ORDER BY` when it is missing. `OrderExtension` runs at `-32` and bails out as soon as the client's
`OrderFilter` has set an ordering, which would otherwise leave a bare `ORDER BY occurred_at` — not a
total order, since `occurredAt` is not unique — duplicating and skipping rows across pages and
invalidating the identifier-based cursor.

Known upstream quirk: when a page comes back **empty**,
`PartialCollectionViewNormalizer::cursorPaginationFieldsFromUrl()` derives the next cursor with
`(float)` arithmetic on the current one, which is meaningless for a UUID. The `hydra:next` link on
an empty page is therefore unusable; the links on non-empty pages are built from the real entity and
are correct.

## Filters

| service | kind | properties |
| --- | --- | --- |
| `…filter.cursor` | `KeysetCursorFilter` | `id[gt\|gte\|lt\|lte]` |
| `…filter.exact` | `SearchFilter` | `actorType`, `actorId`, `entityType`, `entityId`, `rootType`, `rootId`, `requestId` |
| `…filter.partial` | `SearchFilter` (`ipartial`) | `actorLabel`, `entityLabel`, `rootLabel` |
| `…filter.action` | `BackedEnumFilter` | `action` |
| `…filter.occurred_at` | `DateFilter` | `occurredAt[before\|after\|strictly_before\|strictly_after]` |

`entityType`, never a class name: a fully qualified name does not survive a refactor, leaks the
namespace layout to every reader, and is miserable in a query string. `entity_class` is stored beside
it as information and is deliberately not filterable.

There is **no order filter**. A keyset cursor is only meaningful against the sort key it was issued
for, so a client-chosen order would make every later page a mix of duplicates and gaps. The order is
fixed at `(occurredAt DESC, id DESC)`.

`action` uses `BackedEnumFilter` rather than an exact `SearchFilter`: it validates the value against
`AuditAction::cases()` and publishes the enum in the OpenAPI schema. It relies on the column's
`enumType` mapping.

Prefix is `audit_trail.bridge.api_platform.filter.` (see `RegisterAuditFeedPass::FILTER_PREFIX`).
Redefine any of those service ids to replace a filter; the operation references them by id.

## Two views, without touching the entity

The entity has no `#[Groups]`, and serializer groups only *restrict* — a property in no group is
dropped, so declaring `groups` in the resource metadata would serialize nothing. Shipping serializer
mapping from the bundle would put host-visible group names in a generic package.

The collection therefore uses `AbstractNormalizer::IGNORED_ATTRIBUTES` with
`['changes', 'metadata', 'ip', 'requestId']`; the item operation sets no restriction. This is stock
Symfony context honoured by `AbstractItemNormalizer::isAllowedAttribute()`, needs no property
metadata, and stays additive: a new column shows up in both views until someone says otherwise.

Caveat: OpenAPI and JSON Schema are generated from property metadata and serializer groups, so the
documented collection schema still lists the four hidden properties. Overriding the operation's
`normalizationContext` with real groups is the only fix, and that requires serializer mapping the
host owns.

## Security

The pass consumes one parameter, `audit_trail.bridges.api_platform.security`, and emits it on the
resource and both operations when it is non-null. It does not know about the three access modes:
`AuditTrailExtension::auditFeedSecurity()` collapses `access.grants` / `access.expression` /
`access.public` into that single expression (or null, for `public`), escaping the host's attribute and
subject strings on the way into the expression literals. `Configuration` rejects a build that declares
none of the three, or more than one, and the extension refuses to compile when the resolved expression
needs `symfony/expression-language` and it is absent.

Keeping the collapse in the extension and the emission in the pass is deliberate: the extension is the
only place with the processed configuration, and the pass is the only place that can see whether
api-platform is really wired.

## Files

| file | role |
| --- | --- |
| `AuditFeedOptions.php` | normalized configuration value object (prefix, page sizes, security) |
| `DependencyInjection/RegisterAuditFeedPass.php` | conditional wiring, the only entry point |
| `Metadata/AuditFeedResourceNameCollectionFactory.php` | declares the class as a resource |
| `Metadata/AuditFeedResourceMetadataCollectionFactory.php` | operations, pagination, contexts, security |
| `Filter/KeysetCursorFilter.php` | UUID range predicate for the cursor |
| `Extension/CursorTiebreakerExtension.php` | keeps `ORDER BY` a total order |
