# API Platform bridge

A read-only, keyset-paginated HTTP feed over the trail. Nothing is loaded when
`api-platform/core` is absent, and the entity carries no API Platform attribute, so the bundle boots
either way.

## Enabling it

Installing `api-platform/core` is enough — the bridge auto-detects it. Detection requires the package
installed **and** registered **and** configured with Doctrine ORM support, so a partial installation
switches the bridge off rather than producing a broken container.

The feed is assembled by a compiler pass, `RegisterAuditFeedPass`, installed from
`AuditTrailBundle::build()`. It registers the filters, the cursor tiebreaker query extension and two
resource-metadata decorators, all from container parameters — there is no service file to include and
nothing to import in your own configuration.

```yaml
audit_trail:
    bridges:
        api_platform:
            enabled: ~                    # null = auto-detect
            route_prefix: '/audit_logs'
            items_per_page: 50
            max_items_per_page: 200
            security: "is_granted('ROLE_AUDIT_READER')"
```

Set `enabled: false` to keep the package but not expose the trail.

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
| `entityClass`, `entityId`, `entityLabel` | yes | yes |
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

| Query parameter | Kind | Example |
| --- | --- | --- |
| `id[gt\|gte\|lt\|lte]` | keyset cursor (UUID) | `?id[lt]=0197f1c2-8a3e-7c21-9b44-2f0c1d8e5a77` |
| `entityClass` | exact | `?entityClass=App\Entity\Invoice` |
| `entityId` | exact | `?entityClass=App\Entity\Invoice&entityId=42` |
| `rootType`, `rootId` | exact | `?rootType=questionnaire&rootId=0197f1c2-…` |
| `actorType`, `actorId` | exact | `?actorType=service_account&actorId=erp-nightly` |
| `action` | backed enum | `?action=delete` |
| `occurredAt[before\|after\|strictly_before\|strictly_after]` | date | `?occurredAt[after]=2026-02-01T00:00:00%2B00:00` |
| `order[id\|occurredAt]` | order | `?order[occurredAt]=asc` |
| `itemsPerPage` | page size, capped at `max_items_per_page` | `?itemsPerPage=20` |

Fully qualified class names round-trip as-is (URL-encode the backslashes). `action` uses
`BackedEnumFilter` rather than an exact search so the value is validated against `AuditAction::cases()`
and published in the OpenAPI schema.

Combining them is the point:

```
GET /audit_logs?rootType=questionnaire&rootId=0197f1c2-…&action=delete&occurredAt[after]=2026-01-01T00:00:00%2B00:00
```

Each filter is a container service under `audit_trail.bridge.api_platform.filter.` — `cursor`,
`exact`, `action`, `occurred_at`, `order`. Redefining one of those service ids replaces that filter;
the operation references them by id.

## Security

The bundle ships **no** security expression, and that is a decision rather than an oversight: it
refuses to invent a permission name, because `ROLE_ADMIN`, `ROLE_AUDITOR` or
`is_granted('AUDIT_READ', object)` is your application's vocabulary, not a library's.

```yaml
audit_trail:
    bridges:
        api_platform:
            security: "is_granted('ROLE_AUDIT_READER')"
```

The expression is applied to the resource and to both operations. Any API Platform security
expression works, including object-aware ones on the item operation.

**An expression needs two packages your application must have.** API Platform evaluates `security`
through its own access checker, which raises a `LogicException` when either is missing:

| Missing | Message |
| --- | --- |
| `symfony/expression-language` | `The "symfony/expression-language" library must be installed to use the "security" attribute.` |
| `symfony/security` | `The "symfony/security" library must be installed to use the "security" attribute.` |

Neither is a dependency of this bundle — it does not need them to capture, only you need them to
evaluate the expression you wrote. `composer require symfony/expression-language` if the feed answers
with that error.

With `security: ~`, the resource metadata carries **no security attribute at all** — the feed is
exactly as protected as your firewall and `access_control` make it. Which means: if the route is
reachable and you configured nothing, your trail is public. Configure one, or cover the prefix:

```yaml
security:
    access_control:
        - { path: '^/audit_logs', roles: ROLE_AUDIT_READER }
```

Both are legitimate. Choosing neither is not.
