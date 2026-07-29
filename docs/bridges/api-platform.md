# API Platform bridge

A read-only, keyset-paginated HTTP feed over the trail. Nothing is loaded when
`api-platform/core` is absent, and the entity carries no API Platform attribute, so the bundle boots
either way.

**Requires `api-platform/core` >= 4.3.9.** The feed's cursor covers `occurredAt` as well as `id`, and
api-platform did not format a date cursor as ISO 8601 when building `hydra:next` until that release
([api-platform/core#8241](https://github.com/api-platform/core/pull/8241)) — before it, the value was
cast to a string and every paginated response failed. Enabling the feed on an older version is
therefore a build failure, `UnsupportedApiPlatformVersion`, rather than a 500 on the first page. The
rest of the bundle has no such floor: capture and the repository work with any api-platform, or none.

## Enabling it

The feed is **off by default and opt-in**. Installing `api-platform/core` is deliberately not enough:
this bridge publishes actors, IP addresses and field-level diffs over HTTP, so a package appearing in
`vendor/` must never be what exposes an audit log. Turn it on by name, and declare who may read it in
the same breath — the two are not separable, and omitting `access` is a configuration error rather
than an open feed.

The feed is assembled by a compiler pass, `RegisterAuditFeedPass`, installed from
`AuditTrailBundle::build()`. It registers the filters, the cursor tiebreaker query extension and two
resource-metadata decorators, all from container parameters — there is no service file to include and
nothing to import in your own configuration.

```php
// config/packages/audit_trail.php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('audit_trail', [
        'bridges' => ['api_platform' => [
            'enabled' => true,
            'route_prefix' => '/audit-logs',
            'items_per_page' => 50,
            'max_items_per_page' => 200,
            'access' => ['grants' => ['show audit']],   // exactly one access mode — see Security
        ]],
    ]);
};
```

`enabled: false` (the default) keeps every service in the container and captures as usual; only the
HTTP surface is absent.

## Endpoints

| Method | URI | Payload |
| --- | --- | --- |
| `GET` | `{prefix}{._format}` | keyset-paginated collection, **light** view |
| `GET` | `{prefix}/{id}{._format}` | one entry, **full** view |

Writes are not declared, and not hidden behind `NotExposed`. Both URIs exist as GET-only Symfony
routes, so `POST` / `PUT` / `PATCH` / `DELETE` match the path but not the method and the router answers
**405 Method Not Allowed** with an `Allow: GET` header. A 404 would wrongly suggest the trail is not
there at all.

There is no nested per-aggregate route. Inline history is the collection filtered on the root — see
[aggregate-history.md](../aggregate-history.md#over-http-with-the-api-platform-bridge-on).

## List vs detail payloads

The collection omits the four heavy or sensitive properties; the item returns everything.

| Property | Collection | Item |
| --- | --- | --- |
| `id`, `action`, `occurredAt` | yes | yes |
| `entityType`, `entityId`, `entityLabel` | yes | yes |
| `entityClass` | yes | yes |
| `actorType`, `actorId`, `actorLabel` | yes | yes |
| `rootType`, `rootId`, `rootLabel` | yes | yes |
| `changes` | **no** | yes |
| `metadata` | **no** | yes |
| `ip` | **no** | yes |
| `requestId` | **no** | yes |

This is implemented with `AbstractNormalizer::IGNORED_ATTRIBUTES`, not serializer groups. Groups only
*restrict*: a property in no group is dropped, so declaring groups from a generic bundle would either
serialize nothing or force host-visible group names into a third-party package. The chosen mechanism
is stock Symfony context, needs no property metadata, and stays additive — a new column shows up in
both views until someone decides otherwise.

**Caveat:** OpenAPI and JSON Schema are generated from property metadata, not from
`IGNORED_ATTRIBUTES`, so the documented collection schema still lists the four hidden properties. The
only real fix is overriding the operation's `normalizationContext` with serializer groups your
application owns.

## Keyset pagination

```
paginationViaCursor: [['field' => 'id', 'direction' => 'DESC']]
paginationPartial:   true
order:               ['id' => 'DESC']
```

The primary key is a UUID v7 — time-ordered — so it is both the sort key and the cursor.

Offset paging is not offered, for two reasons that both grow with the table:

- **it drifts.** A trail only ever grows, and it grows at the head. Any insert between two requests
  shifts every offset, so a client walking `page=1,2,3…` sees rows twice and misses others.
- **it costs.** `OFFSET n` re-scans the first *n* rows, and the `COUNT(*)` that produced the page count
  scans the table. `paginationPartial: true` drops the count as well.

Keyset paging reads a bounded index range and is immune to concurrent inserts — which the bundle
asserts by inserting an entry mid-walk and checking that the pages neither overlap nor skip.

`KeysetCursorFilter` exists because API Platform's own `RangeFilter` cannot express this: it coerces
every bound through `is_numeric()` and drops what fails, so `id[lt]=<uuid>` is silently ignored and
every page returns the first one. The bridge's filter compares against a real
`Symfony\Component\Uid\Uuid`, bound with the `uuid` Doctrine type. An unparseable cursor is ignored
(and logged at notice level) rather than emptying the feed.

`CursorTiebreakerExtension` appends `id` to the `ORDER BY` when a client's `OrderFilter` has replaced
it. `occurredAt` is not unique, so ordering by it alone is not a total order: rows sharing a timestamp
could swap between two requests, duplicating and skipping entries and invalidating the cursor.

**Known upstream quirk:** on an *empty* page, API Platform derives the next cursor with float
arithmetic on the current one, which is meaningless for a UUID. The `hydra:next` link of an empty page
is unusable; links on non-empty pages are built from the real entity and are correct. Stop walking
when a page comes back empty.

## Filters

Every parameter below is generated from a filter service and therefore appears in the OpenAPI
document with its own description — `/docs` and `/docs.jsonopenapi` document the feed without you
writing a line of schema.

### Selecting by coordinates (exact)

| Query parameter | Example |
| --- | --- |
| `entityType` | `?entityType=invoice` |
| `entityId` | `?entityType=invoice&entityId=42` |
| `rootType`, `rootId` | `?rootType=questionnaire&rootId=0197f1c2-…` |
| `actorType`, `actorId` | `?actorType=service_account&actorId=erp-nightly` |
| `requestId` | `?requestId=d3f1a90c4b2e` |

`entityType` is the short name the entity declares with `#[Auditable(type: 'invoice')]`, or the
kebab-case of its class name when it declares none — see
[attributes.md](../attributes.md#the-name-entries-are-filed-under). Class names are not part of this
contract: `entityClass` is returned in the payload as information, and nothing filters on it, so a
refactor never invalidates a saved query or a bookmarked URL.

`requestId` is the one that answers a different shape of question: it returns **every change one HTTP
call made**, which is how a single suspicious request becomes a complete account of itself.

### Searching by name (case-insensitive partial)

| Query parameter | Example |
| --- | --- |
| `actorLabel` | `?actorLabel=martin` |
| `entityLabel` | `?entityLabel=jean%20dupont` |
| `rootLabel` | `?rootLabel=acme` |

Identifiers are what a machine searches by; a person has a name. These three search the snapshotted
labels — the only place a name survives once the row it came from has been renamed or deleted, which
is precisely when someone asks.

### Narrowing by time and action

| Query parameter | Kind | Example |
| --- | --- | --- |
| `action` | backed enum | `?action=delete` |
| `occurredAt[before\|after\|strictly_before\|strictly_after]` | date range | `?occurredAt[after]=2026-02-01T00:00:00%2B00:00` |

`action` uses `BackedEnumFilter` rather than an exact search, so the value is validated against
`AuditAction::cases()` and published as an enum in the OpenAPI schema instead of a free string.

### Walking the feed

| Query parameter | Kind | Example |
| --- | --- | --- |
| `occurredAt[lt\|lte\|gt\|gte]` **+** `id[lt\|lte\|gt\|gte]` | keyset cursor | `?occurredAt[lt]=2026-02-01T…&id[lt]=0197f1c2-…` |
| `itemsPerPage` | page size, capped at `max_items_per_page` | `?itemsPerPage=20` |

**Take the cursor from `hydra:view.next`, do not build it.** It has two halves, and both are required:
sending one alone is ignored rather than applied, because half a cursor over a two-part sort key is
how a feed silently skips and repeats entries. See [Keyset pagination](#keyset-pagination).

There is **no `order` parameter**, deliberately: a cursor is only meaningful against the sort key it
was issued for, so client-chosen ordering and keyset pagination cannot both be offered. The order is
fixed at `(occurredAt DESC, id DESC)`. When you genuinely need another ordering, read the trail from
PHP instead — see [reading-the-trail.md](../reading-the-trail.md).

### Combining them

That is the point:

```
GET /audit-logs?rootType=questionnaire&rootId=0197f1c2-…&action=delete&occurredAt[after]=2026-01-01T00:00:00%2B00:00
```

Each filter is a container service under `audit_trail.bridge.api_platform.filter.` — `cursor`,
`exact`, `partial`, `action`, `occurred_at`. Redefining one of those service ids replaces that filter;
the operations reference them by id, so nothing else has to change.

## What the OpenAPI document says

The bridge fills in the metadata a generated document usually lacks:

- the resource carries a description, and both operations carry a summary and a description of their
  own, under the `Audit trail` tag;
- the collection's description states the pagination contract — follow `hydra:view.next`, both cursor
  halves, fixed order — because that is the part a client gets wrong;
- the cursor parameters describe themselves, including that one half alone is ignored, with
  `format: date-time` and `format: uuid` on the two halves;
- `action` is published as an enum;
- the collection and item schemas differ, since the collection omits `changes`, `metadata`, `ip` and
  `requestId` — see [List vs detail payloads](#list-vs-detail-payloads).

Nothing here needs configuration. If you post-process the document, the operations are reachable
under the `AuditLog` short name.

## Security

The bundle invents no permission name — `ROLE_AUDITOR`, `show audit` or `view` on `audit-logs` is
your application's vocabulary, not a library's. But it does insist that you name one: enabling the
feed without declaring `access` stops the container from compiling, with a message listing the three
ways to say it. Fail-closed, because the alternative is a trail that quietly answers to anyone.

Exactly one of the three modes. They are ordered by how much they ask of you:

### 1. `grants` — the one to reach for

A list of checks; **any one** granting access is enough. A plain string is the attribute alone, and
the mapping form adds a subject, which permission systems anchored on a resource need:

```php
// config/packages/audit_trail.php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('audit_trail', [
        'bridges' => ['api_platform' => [
            'enabled' => true,
            'access' => [
                'grants' => [
                    'show audit',                                          // is_granted('show audit')
                    ['attribute' => 'view', 'subject' => 'audit-logs'],    // is_granted('view', 'audit-logs')
                ],
            ],
        ]],
    ]);
};
```

This covers more than it looks. A role is an attribute. A permission is an attribute. Anything a
Symfony voter can decide — tenant membership, a time window, a feature flag, a database lookup — is
an attribute too: **write the voter, then name its attribute here.** That is why there is no
"custom access service" option; Symfony already has the extension point, and duplicating it inside
this bundle would only give you a second, worse one.

### 2. `expression` — for what `grants` cannot say

A raw API Platform security expression, passed through untouched. Reach for it when the decision
needs `and`/`or`, the `user` object, the request, or `object` on the item operation:

```php
            'access' => [
                'expression' => "is_granted('show audit') and user.isInternal()",
            ],
```

### 3. `public` — an opt-out you have to say out loud

```php
            'access' => ['public' => true],
```

No security attribute is emitted and the feed is exactly as protected as your firewall and
`access_control` make it — which is a legitimate architecture, and a disaster if you meant something
else. Pair it with a rule that actually covers the prefix:

```php
// config/packages/security.php
$container->extension('security', [
    'access_control' => [
        ['path' => '^/audit-logs', 'roles' => 'ROLE_AUDIT_READER'],
    ],
]);
```

Whichever mode you choose, it is applied to the resource and to **both** operations. For a finer
split — say, listing allowed but the detail view restricted, since the detail view is what carries
`changes`, `ip` and `metadata` — use `expression` with `object`, or gate the item route in
`access_control`.

**Access control needs two packages your application must have.** Every mode except `public` becomes
an API Platform `security` expression, evaluated by its access checker, which raises a
`LogicException` when either is missing. The bundle checks for the expression language **at compile
time** and refuses to build with the `composer require` line in the message, rather than letting you
discover it as a 500 on the first request:

| Missing | Message |
| --- | --- |
| `symfony/expression-language` | `The "symfony/expression-language" library must be installed to use the "security" attribute.` |
| `symfony/security` | `The "symfony/security" library must be installed to use the "security" attribute.` |

Neither is a dependency of this bundle — it does not need them to capture, only you need them to
evaluate the expression you wrote. `composer require symfony/expression-language` if the feed answers
with that error.

Neither is a dependency of this bundle — capture does not need them; only evaluating the rule you
wrote does.
