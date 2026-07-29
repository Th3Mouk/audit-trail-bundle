# Audit Trail Bundle

**Knowing who changed what, and when — and a trail that is still readable months later.**

[![CI](https://img.shields.io/github/actions/workflow/status/Th3Mouk/audit-trail-bundle/ci.yml?branch=main&label=CI)](https://github.com/Th3Mouk/audit-trail-bundle/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/th3mouk/audit-trail-bundle)](https://packagist.org/packages/th3mouk/audit-trail-bundle)
[![PHP version](https://img.shields.io/packagist/dependency-v/th3mouk/audit-trail-bundle/php)](https://packagist.org/packages/th3mouk/audit-trail-bundle)
[![License](https://img.shields.io/packagist/l/th3mouk/audit-trail-bundle)](LICENSE)

A Doctrine audit trail for Symfony: field-level before/after diffs written in the same
transaction as the change they describe, anchored to the aggregate they belong to.

## Why

Most applications settle for one of two things.

A `last_modified_by` column, which answers "who touched this most recently" and nothing else —
not what changed, not what it was before, not the four edits that came before this one.

Or a generic trail full of orphaned identifiers: `membership 4217 deleted`, `role_id 7`,
`user_id 512`. Six months later the membership is gone, the role has been renamed, the user has
been anonymised, and the entry is unreadable. It is evidence that something happened, not a
record of what happened.

Two things here are different from that:

- **Label snapshots survive deletion.** Every reference an entry captures carries the
  human-readable name of the row it points at, denormalised at capture time. `role Manager was
  removed from Jean Dupont in Acme` stays true after the grant, the role and the user are all
  gone. No foreign key can promise that; a snapshot can.
- **Secrets are recorded as changed, without their value ever being stored.** A property marked
  `#[AuditMasked]` is never *read* by the serializer — the mask is emitted from the mere presence
  of the key in the Doctrine change set. `the password was rotated` is in the trail; the hash
  never was.

The rest follows from the same idea: capture happens in `onFlush`, while the entity is still
hydrated and its associations still resolvable, so a deletion can carry the whole state as it
stood. And the audit row joins the caller's transaction, so a rolled-back change takes its
audit entry with it.

## Quickstart

```bash
composer require th3mouk/audit-trail-bundle
```

Enable the bundle:

```php
// config/bundles.php
return [
    // ...
    Th3Mouk\AuditTrail\AuditTrailBundle::class => ['all' => true],
];
```

Mark one entity:

```php
use Th3Mouk\AuditTrail\Attribute\AuditLabel;
use Th3Mouk\AuditTrail\Attribute\AuditScope;
use Th3Mouk\AuditTrail\Attribute\Auditable;

#[ORM\Entity]
#[Auditable]
#[AuditScope(root: Organization::class, via: 'organization')]
class OrganizationMembership
{
    #[AuditLabel]
    public function describe(): string
    {
        return $this->role->getName().' — '.$this->user->getFullName();
    }

    // ...
}
```

Create the table. The bundle maps its own entity into your default entity manager, so your
usual tooling sees it:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

That is the whole setup. Zero configuration is required.
`bin/console config:dump-reference audit_trail` documents every option, and
[docs/configuration.md](docs/configuration.md) explains the two that matter in practice: the
`enabled` kill-switch and `listener_priority`.

### Before and after

Revoking a role. Here is what a naive audit trail stores:

```json
{
  "table": "organization_membership",
  "record_id": 4217,
  "operation": "DELETE",
  "changed_by": 88,
  "changed_at": "2026-07-29T09:14:02+00:00"
}
```

Every name in that row is a number pointing at something that may no longer exist. Here is the
`audit_logs` row this bundle writes for the same flush:

```json
{
  "id": "0198a3f1-6b2c-7c31-9e4a-8f21d5b7c004",
  "action": "delete",
  "entity_class": "App\\Entity\\OrganizationMembership",
  "entity_id": "4217",
  "entity_label": "Manager — Jean Dupont",
  "actor_type": "operator",
  "actor_id": "88",
  "actor_label": "Alice Martin",
  "changes": {
    "user": { "class": "App\\Entity\\User", "id": "512", "label": "Jean Dupont" },
    "role": { "class": "App\\Entity\\Role", "id": "7", "label": "Manager" },
    "organization": { "class": "App\\Entity\\Organization", "id": "31", "label": "Acme" },
    "grantedAt": "2026-02-03T10:22:41+00:00"
  },
  "root_type": "organization",
  "root_id": "31",
  "root_label": "Acme",
  "occurred_at": "2026-07-29T09:14:02+00:00",
  "request_id": "d3f1a90c4b2e",
  "ip": "203.0.113.7",
  "metadata": null
}
```

Drop the database and this row still reads: *role **Manager** was removed from **Jean Dupont** in
**Acme**, by **Alice Martin**.*

`"operator"` is the host application's word, not the bundle's — see
[Extending it](#extending-it). Updates use `{"field": {"before": …, "after": …}}`; creations and
deletions record flat full state, which is what keeps a deletion legible.

## Attributes

| Attribute | Target | What it does |
| --- | --- | --- |
| `#[Auditable]` | class | Opts the entity in. Inherited, so marking a base class audits its children; a child opts back out with `#[Auditable(enabled: false)]`. |
| `#[AuditLabel]` | property or method | Designates the entity's human title. Snapshotted onto every entry that names it. Falls back to `__toString()`, then to nothing. |
| `#[AuditScope]` | class | Anchors entries to their aggregate root: `#[AuditScope(root: Post::class, via: 'comment.post')]` walks getters, identifiers only, never a query. |
| `#[NotAuditable]` | property | Excludes the property entirely. Not stored, and **not row-triggering**. |
| `#[AuditMasked]` | property | Records *that* the property changed, never its value. `#[AuditMasked(mask: '[redacted]')]` overrides the global sentinel. |

**Masked and ignored are not two flavours of the same thing**, and it is the one distinction worth
reading twice. Ignored properties are stripped *before* the decision to write a row: a flush that
only bumps `updatedAt` or a hit counter records nothing at all. Masked properties still trigger a
row: a flush whose only change is `password` produces one entry, reading
`{"password": {"before": "********", "after": "********"}}`. That is how you keep a high-churn
technical column out of the trail without also hiding the fact that a credential was rotated.

Auditing is opt-in on purpose — the audited surface stays a greppable, reviewable fact.
[docs/attributes.md](docs/attributes.md) is the full reference for the attributes and the
row-trigger rule; [docs/architecture.md](docs/architecture.md) walks the capture pipeline, value
mapping and the no-query guarantee.

## Reading the trail

Because the root is denormalised onto every entry, a per-aggregate history panel is one indexed
read — no joins, no fan-out:

```php
use Th3Mouk\AuditTrail\Repository\AuditLogRepository;

$history = $auditLogs
    ->forRoot('organization', (string) $organization->getId(), $beforeCursor)
    ->setMaxResults(50)
    ->getQuery()
    ->getResult();
```

`forEntity()` and `forActor()` are the two siblings. All three return a `QueryBuilder` ordered by
identifier descending — the primary key is a UUID v7, so it is both the sort key and a cursor.
There is deliberately no offset paging and no total count: neither can be served cheaply on a
table meant to grow forever. See [docs/aggregate-history.md](docs/aggregate-history.md).

### Changes the ORM never sees

Bulk DQL, raw SQL, a data migration: none of them produce a change set, so nothing can capture
them automatically. Say so explicitly instead, and the entry is indistinguishable from a captured
one — actor, timestamp and request context are filled in for you:

```php
use Th3Mouk\AuditTrail\AuditLoggerInterface;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;

$this->audit->deleted(
    OrganizationMembership::class,
    4217,
    ['role' => 'Manager', 'user' => 'Jean Dupont'],
    'Manager — Jean Dupont',
    AuditScopeRef::of('organization', 31, 'Acme'),
    ['origin' => 'bulk-revoke'],
);
$this->entityManager->flush();
```

`created()`, `updated()`, `deleted()` and the lower-level `record()` all queue an entry; the
logger never flushes on its own, so *when* the transaction closes stays your decision.

## Extending it

The bundle ships defaults, not policy. Every opinionated piece is an interface with a replaceable
implementation — and, deliberately, **no actor taxonomy and no user class**. `Actor` is three
free-form strings (`id`, `type`, `label`); there is no `ActorType` enum, no built-in
`user`/`system` distinction, and nothing in `src/` names a permission or an entity of yours.

The same rule holds inside the bundle: the two seams the Gedmo bridge needs are declared in the
core with no mention of Gedmo, which is why the capture pipeline has never heard of it.

| Seam | Interface | How to plug in | Default |
| --- | --- | --- | --- |
| Who did it | `Actor\ActorResolverInterface` | tag `audit_trail.actor_resolver` (`priority`) | `SecurityTokenActorResolver`, registered only when a `security` extension is present, at priority `-100` |
| Human title | `Capture\LabelResolverInterface` | decorate the service | `#[AuditLabel]`, then `__toString()`, then null |
| Aggregate root | `Capture\ScopeResolverInterface` | decorate the service | `#[AuditScope]`, or `Scope\AuditScopeProviderInterface` on the entity itself |
| Value rendering | `Capture\ValueSerializerInterface` | tag `audit_trail.value_serializer` (`priority`) | scalar → date → enum → entity reference → `Stringable` chain |
| Capture veto | `Capture\CaptureGateInterface` | tag `audit_trail.capture_gate` (`priority`) | kill-switch and cascade suppression; **all** gates must agree before an entry is written |
| What an update *means* | `Capture\ActionResolverInterface` | tag `audit_trail.action_resolver` | none in core; the Gedmo bridge reclassifies a logical delete as a `delete` |
| Fields to leave out | `Capture\FieldExclusionInterface` | tag `audit_trail.field_exclusion` | none in core; the Gedmo bridge drops fields whose change is diverted to a translation |
| Where rows go | `Storage\AuditStorageInterface` | decorate or replace | `DoctrineAuditStorage`, which enlists the entry in the ongoing flush |

Each interface has a public alias, so autowiring and `#[AsDecorator]` both work out of the box.

Actor resolution is the seam most applications touch first. Resolvers are asked in priority order
and return `null` to defer; when nobody answers, the change is still recorded, attributed to
nobody:

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Model\Actor;

#[AutoconfigureTag('audit_trail.actor_resolver', ['priority' => 100])]
final readonly class ApiKeyActorResolver implements ActorResolverInterface
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function resolve(): ?Actor
    {
        $key = $this->requests->getCurrentRequest()?->headers->get('X-Api-Key');

        return null === $key ? null : Actor::of($key, 'api_key', 'Integration key');
    }
}
```

Resolvers must be stateless — the actor is re-read on every call, so a long-running worker can
never attribute one message's changes to another message's principal. Full walkthrough in
[docs/extending.md](docs/extending.md).

## Bridges

Both are auto-detected from the installed packages and can be forced on or off. The bundle boots
with neither installed.

**Gedmo** (`gedmo/doctrine-extensions`) — audits translated content, which `Translatable` moves
out of the entity change set before a plain listener can see it, and maps a `SoftDeleteable`
logical delete onto a real `delete` action instead of an update of a date column. Two honest gaps:
a translation inserted for an entity that has no identifier yet goes straight through the DBAL and
cannot be captured from `onFlush` (assign identifiers in the constructor and the case disappears),
and a bare locale switch is not recorded. Details in
[`src/Bridge/Gedmo/README.md`](src/Bridge/Gedmo/README.md).

**API Platform** (`api-platform/core`) — exposes the trail as a read-only, keyset-paginated feed
at `/audit_logs`, with filters on actor, entity, root, action and date, and an `id[lt]` UUID
cursor. Writes are not declared: the routes are GET-only, so a `POST` gets a 405. No security
expression is emitted unless you configure one — the bundle will not invent a role name, which
means an unconfigured feed is only as protected as your firewall makes it. Details in
[`src/Bridge/ApiPlatform/README.md`](src/Bridge/ApiPlatform/README.md).

## Requirements

- PHP 8.4+
- Doctrine ORM ^3.2, DBAL ^4.0, DoctrineBundle ^2.13
- Symfony ^7.4 || ^8.0

Optional: `api-platform/core` ^4.1, `gedmo/doctrine-extensions` ^3.16,
`symfony/security-bundle` (for the default actor resolver).

Tested against PHP 8.4 and 8.5, Symfony 7.4 and 8.1, lowest and highest dependency resolutions,
SQLite and PostgreSQL — and, in a job of its own, with every optional package genuinely
uninstalled, because "works without them" is worth proving rather than asserting.

## Testing

```bash
composer test        # unit, integration and bridge suites
composer ci          # cs:check, phpstan, then every suite
```

The suites default to an in-memory SQLite database, so there is nothing to provision. Export
`DATABASE_URL` to run them against PostgreSQL instead. PHPStan runs at level 8 with no baseline.

## Documentation

Start at [docs/index.md](docs/index.md), or jump straight to what you need:

| Page | Contents |
| --- | --- |
| [docs/installation.md](docs/installation.md) | Install, register, create the table, rename it, `jsonb` on PostgreSQL |
| [docs/attributes.md](docs/attributes.md) | The five attributes, the field-policy table, the row-trigger rule |
| [docs/configuration.md](docs/configuration.md) | Every `audit_trail` option, and when listener priority matters |
| [docs/aggregate-history.md](docs/aggregate-history.md) | `#[AuditScope]`, the dotted walk, keyset cursors, building a history panel |
| [docs/actor.md](docs/actor.md) | Actor resolution, why there is no taxonomy, impersonation, unknown actors |
| [docs/manual-logging.md](docs/manual-logging.md) | Recording what the ORM cannot see, and when to refactor instead |
| [docs/extending.md](docs/extending.md) | Every seam, with a worked example each |
| [docs/bridges/gedmo.md](docs/bridges/gedmo.md) | Translation auditing and soft deletes: what is covered, and what is not |
| [docs/bridges/api-platform.md](docs/bridges/api-platform.md) | The read feed: endpoints, filters, cursor pagination, security |
| [docs/architecture.md](docs/architecture.md) | For contributors: the capture pipeline, the invariants, what is out of scope |
| [docs/faq.md](docs/faq.md) | Cost per write, table growth, reads, tamper-evidence, JSON columns |

## Contributing

Bug reports, ideas and pull requests are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) covers the
gates, where a test belongs, and the two invariants that are not negotiable.
[AGENTS.md](AGENTS.md) is the orientation guide for anyone — human or agent — about to change
something: the repository map, the four rules that settle most design questions, a recipe per kind of
change, and the traps that have already cost time here. Security issues: [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE).
