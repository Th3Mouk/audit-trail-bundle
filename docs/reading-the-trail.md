# Reading the trail from PHP

The API Platform bridge is one way to read the trail, and an optional one. Everything it exposes is
available directly from `AuditLogRepository`, which is a plain Doctrine repository: no bridge, no
HTTP, no serializer. Reach for it whenever the answer belongs inside your application — a history
panel rendered by Twig, a console report, an export, a Messenger handler, a page the feed's fixed
ordering does not suit.

## Getting the repository

It is a `ServiceEntityRepository`, registered and autowirable like your own:

```php
use Th3Mouk\AuditTrail\Repository\AuditLogRepository;

final readonly class ShowHistory
{
    public function __construct(private AuditLogRepository $auditLogs)
    {
    }
}
```

`$entityManager->getRepository(AuditLog::class)` works too, and returns the same service.

## What the four methods return

Every one of them returns a **`QueryBuilder`**, not results. That is deliberate: you decide the page
size, whether to hydrate entities or arrays, and what else to add — and nothing runs until you ask.

| Method | Answers |
| --- | --- |
| `createFeedQueryBuilder(?AuditCursor $before = null)` | Everything, newest first. The global feed. |
| `forEntity(string $entityTypeOrClass, string $entityId, ?AuditCursor $before = null)` | The history of one row. Takes the type, or the class it derives from. |
| `forRoot(string $rootType, string $rootId, ?AuditCursor $before = null)` | The history of a whole aggregate, `#[AuditScope]`'s reason for existing. |
| `forActor(?string $actorType, ?string $actorId, ?AuditCursor $before = null)` | What one principal did. Either argument may be null to widen the question. |

All four order by `(occurredAt DESC, id DESC)` — the feed's canonical order — and all four take the
same optional cursor.

## One record's history

The case the bundle is built around: a panel on a fiche, listing what happened to it.

```php
$entries = $this->auditLogs
    ->forRoot('organization', (string) $organization->getId())
    ->setMaxResults(50)
    ->getQuery()
    ->getResult();

foreach ($entries as $entry) {
    // $entry is a Th3Mouk\AuditTrail\Entity\AuditLog
    $entry->getAction();       // AuditAction::Create | Update | Delete
    $entry->getActorLabel();   // 'Alice Martin', snapshotted — still true after she leaves
    $entry->getEntityLabel();  // 'Manager — Jean Dupont'
    $entry->getOccurredAt();
    $entry->getChanges();      // ['name' => ['before' => 'Acme', 'after' => 'Acme Corp']]
}
```

Because the aggregate is denormalised onto every entry, this is one indexed read: no joins, and no
walking children to collect their identifiers first. `idx_audit_root` covers
`(root_type, root_id, occurred_at, id)`, which is the filter *and* the ordering.

`forEntity()` is the narrower sibling — the history of one row rather than of the aggregate it
belongs to:

```php
$entries = $this->auditLogs
    ->forEntity(OrganizationMembership::class, (string) $membershipId)
    ->setMaxResults(20)
    ->getQuery()
    ->getResult();
```

Note that `forEntity()` still finds the entries of a row that no longer exists. That is the point of
`entity_id` being a stored string rather than a foreign key.

## Paging with the cursor

Pass the last entry you displayed, and you get the ones after it. No offset, no count: both get
slower as the table grows, and a trail is a table that only grows.

```php
use Th3Mouk\AuditTrail\Repository\AuditCursor;

$page = $this->auditLogs
    ->forRoot('organization', $organizationId, $before)   // ?AuditCursor, null for the first page
    ->setMaxResults(50)
    ->getQuery()
    ->getResult();

$next = [] === $page ? null : AuditCursor::of(end($page));
```

The cursor carries **both halves of the sort key**, `occurredAt` and `id`. A UUID v7 is
time-ordered, so the identifier alone looks sufficient — and stops being so the moment anything is
backfilled, because an imported entry gets a historical timestamp under an identifier minted today.
From then on `id DESC` and `occurredAt DESC` are two different orders, and a cursor read against the
wrong one skips rows nobody deleted.

It is one predicate, not two ranges: `occurredAt < :t AND id < :id` would drop every entry sharing
the cursor's instant with a smaller identifier — and a single flush stamps all of its entries with
one instant. The repository and the HTTP feed build the same condition, so a page walked through
either is the same page.

## Adding your own criteria

The builders are starting points. The root alias is `audit_log`:

```php
use Th3Mouk\AuditTrail\Enum\AuditAction;

$deletions = $this->auditLogs
    ->createFeedQueryBuilder()
    ->andWhere('audit_log.action = :action')
    ->andWhere('audit_log.occurredAt >= :since')
    ->setParameter('action', AuditAction::Delete)
    ->setParameter('since', new \DateTimeImmutable('-7 days'))
    ->setMaxResults(200)
    ->getQuery()
    ->getResult();
```

Searching by name rather than by identifier — the question a person actually asks — uses the
snapshotted labels:

```php
$qb = $this->auditLogs->createFeedQueryBuilder()
    ->andWhere('LOWER(audit_log.actorLabel) LIKE :name')
    ->setParameter('name', '%'.strtolower($name).'%');
```

## Reading many entries efficiently

For an export or a report, hydrate arrays rather than entities and iterate rather than collect:

```php
$query = $this->auditLogs->createFeedQueryBuilder()->getQuery();

foreach ($query->toIterable([], AbstractQuery::HYDRATE_ARRAY) as $row) {
    // $row['changes'] is already an array — the column is JSON
    $writer->write($row);
}
```

`AuditLog` is mapped `readOnly: true`, so Doctrine never computes an update change set for a trail
row it hydrated. Nothing is supposed to edit one, and nothing pays for the possibility.

## What not to do

**Do not write through the repository.** There is no `save`, and adding one would be a mistake: an
entry has to join the transaction of the change it describes, which is what capture does and what a
repository call cannot. Recording something the ORM cannot see is what
[manual-logging.md](manual-logging.md) is for.

**Do not delete through it either.** The trail is append-only by intent; retention is a policy
decision, and doing it in application code means the code that can prune the trail is the same code
the trail is watching. See [faq.md](faq.md#can-someone-tamper-with-the-trail).

## The same questions over HTTP

If the API Platform bridge is enabled, each of these has an equivalent — `rootType` + `rootId` for an
aggregate, `entityType` + `entityId` for a row, `actorId` for a principal, `actorLabel` for a name.
See [bridges/api-platform.md](bridges/api-platform.md#filters). The two read the same table with the
same indexes; the repository is simply the one that does not need a route.
