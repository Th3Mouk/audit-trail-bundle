# Aggregate history

## The problem `#[AuditScope]` solves

"Show me everything that happened to this questionnaire" is not the same question as "show me
everything that happened to this row". The interesting changes happened three levels down — an
answer was edited, a pillar was reweighted — and each of them is a row in a different table.

Reconstructing that from `entity_class` + `entity_id` means knowing the whole object graph at read
time and issuing one query per level. So the aggregate is **denormalised onto the entry** at write
time: three columns (`root_type`, `root_id`, `root_label`), one index
(`idx_audit_root (root_type, root_id, occurred_at)`), one query at read time.

```php
use Th3Mouk\AuditTrail\Attribute\AuditScope;
use Th3Mouk\AuditTrail\Attribute\Auditable;

#[ORM\Entity]
#[Auditable]
#[AuditScope(root: Questionnaire::class, via: 'section.questionnaire')]
class Answer { /* ... */ }
```

## The dotted path

`via` is a dotted path walked from the audited entity towards its root. Each segment is resolved, in
order, against:

1. `get<Segment>()`
2. `is<Segment>()`
3. `<segment>()`
4. a public property `$segment`

so `via: 'section.questionnaire'` means `getSection()->getQuestionnaire()`. Multi-hop paths of any
length work; one hop (`via: 'post'`) is the common case.

`type` is the short discriminator written to `root_type`. It defaults to the snake_cased short name
of `root` — `Questionnaire` → `questionnaire`, `SsqPost` → `ssq_post` — and is what you filter on. It
is deliberately not a class name: it survives a namespace move and reads well in a query string
(`?rootType=questionnaire`). Override it when the default collides or when two classes should share
one feed:

```php
#[AuditScope(root: Questionnaire::class, via: 'questionnaire', type: 'ssq')]
```

The root itself does not need `#[AuditScope]` — an entity with no scope simply leaves the three
columns null, so its own changes are found through `entity_class` + `entity_id` rather than through
the aggregate feed. To put the root's own changes in that same feed, give it the
[escape hatch](#the-escape-hatch) and return a reference to itself; the `root_type` is then derived
from the class the same way, so it matches its children's default automatically.

### The walk never queries

Capture runs inside `onFlush`. A query there — worse, a lazy-load — is how an audit listener turns a
fast flush into a slow one and, occasionally, into a corrupted unit of work. So the walk only reads
what is already in memory:

| Situation | Result |
| --- | --- |
| a hop is `null` | no root; the entry is still written |
| a hop is an uninitialised proxy / lazy object | no root |
| a hop is an uninitialised typed property | no root |
| the root is loaded but its label would need I/O | root with `label: null` |
| the root has no identifier yet | no root |
| the segment does not exist at all | `InvalidAuditScope::unreachableSegment()` — a mapping bug |
| the path resolves to the wrong class | `InvalidAuditScope::rootMismatch()` |
| `via` is empty | `InvalidAuditScope::emptyPath()` |

An entry without a root is a valid entry. An entry that cost a `SELECT` inside a flush is a bug.
That asymmetry is the whole design, and it is asserted by the bundle's `NoQueriesDuringFlushTest`.

In practice the common path is free: the entity you just modified is in the identity map, and so is
its owner, because you loaded it to modify it.

## The escape hatch

When the root is conditional, computed or polymorphic, a getter chain cannot express it. Implement
`AuditScopeProviderInterface` on the entity; it **takes precedence** over `#[AuditScope]`.

```php
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Scope\AuditScopeProviderInterface;

#[ORM\Entity]
#[Auditable]
class Attachment implements AuditScopeProviderInterface
{
    public function resolveAuditRoot(): ?AuditRef
    {
        $ownerId = $this->invoice?->getId() ?? $this->contract?->getId();

        return null === $ownerId ? null : AuditRef::of($this->ownerClass(), $ownerId);
    }
}
```

Same rule applies, and it is on you to honour it: **`resolveAuditRoot()` must not query.** Read
identifiers already in memory, or return `null`. Note the example reads `getId()` on the
association and never `getTitle()` — reading a title would initialise a proxy.

The `type` written to `root_type` is derived from the returned `AuditRef::$class` the same way (snake
cased short name); pass a `label` to `AuditRef::of()` if you have one in hand.

For a domain-wide rule rather than a per-entity one, replace the resolver service instead:
[extending.md](extending.md#scope-resolver).

## Building an inline "history of this record" panel

### Straight from the repository

`AuditLogRepository` returns `QueryBuilder`s ordered by identifier descending — the identifier is a
UUID v7, so that is chronological, newest first.

```php
use Th3Mouk\AuditTrail\Repository\AuditLogRepository;

final readonly class QuestionnaireHistory
{
    public function __construct(private AuditLogRepository $auditLogs) {}

    /** @return list<AuditLog> */
    public function forQuestionnaire(string $id, int $limit = 20): array
    {
        return $this->auditLogs
            ->forRoot('questionnaire', $id)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
```

| Method | Feed |
| --- | --- |
| `forRoot(string $rootType, string $rootId, ?Uuid $beforeCursor = null)` | one aggregate |
| `forEntity(string $entityClass, string $entityId, ?Uuid $beforeCursor = null)` | one row |
| `forActor(?string $actorType, ?string $actorId, ?Uuid $beforeCursor = null)` | one principal (null = unconstrained) |
| `createFeedQueryBuilder(?Uuid $beforeCursor = null)` | everything |

Each takes a `$beforeCursor`: pass the identifier of the last entry you displayed to get the next
page. There is no offset paging and no total count, on purpose — see
[bridges/api-platform.md](bridges/api-platform.md#keyset-pagination).

Rendering the panel is then one indexed read, which the bundle asserts as a contract rather than
assuming.

### Over HTTP, with the API Platform bridge on

There is no nested per-aggregate route. Inline history is the collection, filtered:

```
GET /audit_logs?rootType=questionnaire&rootId=0197f1c2-...&itemsPerPage=20
```

and the next page follows the cursor the response advertises, or you build it yourself:

```
GET /audit_logs?rootType=questionnaire&rootId=0197f1c2-...&id[lt]=<last id you rendered>
```

Full filter list and payload shapes: [bridges/api-platform.md](bridges/api-platform.md).

## One caveat about creations

`onFlush` runs *before* the `INSERT`, so an entity whose key the database assigns has no identifier
yet when its `create` entry is built. Such entries carry an empty `entity_id` — they are still in the
right aggregate feed, with the right label and full initial state, but they cannot be found by
identifier. Updates, deletions, and entities keyed by the application (UUID assigned in the
constructor) are unaffected. Assigning identifiers yourself removes the case entirely; see
[architecture.md](architecture.md#the-atomicity-guarantee).
