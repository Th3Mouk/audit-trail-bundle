# Manual logging

## What ORM capture cannot see

Capture hooks Doctrine's `onFlush` and reads change sets. That covers everything that flows through
the entity manager, and nothing else:

| Change | Seen by capture | Why |
| --- | --- | --- |
| `persist()` / property change / `remove()` + `flush()` | yes | it produces a change set |
| bulk DQL — `DELETE FROM App\Entity\Token t WHERE …` | **no** | executed as SQL; no entities, no change sets |
| `$connection->executeStatement(...)` | **no** | the ORM is not involved at all |
| a schema migration's data step | **no** | same |
| `INSERT … SELECT`, `TRUNCATE`, a `COPY` import | **no** | same |
| a side effect in another system (a file deleted, a webhook that revoked a licence) | **no** | not a database write |
| collection / many-to-many deltas | **no** | [deliberately out of scope](architecture.md#deliberately-out-of-scope) |

For those, say so explicitly.

## `AuditLoggerInterface`

```php
use Th3Mouk\AuditTrail\AuditLoggerInterface;

final readonly class PurgeExpiredTokens
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditLoggerInterface $audit,
    ) {}

    public function __invoke(): void
    {
        $expired = $this->em->createQuery(
            'SELECT t.id, t.label FROM App\Entity\Token t WHERE t.expiresAt < :now'
        )->setParameter('now', new \DateTimeImmutable())->getArrayResult();

        $this->em->createQuery('DELETE FROM App\Entity\Token t WHERE t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->execute();

        foreach ($expired as ['id' => $id, 'label' => $label]) {
            $this->audit->deleted(Token::class, $id, ['label' => $label], $label);
        }

        $this->em->flush();
    }
}
```

Four methods, and they fill in everything you should not have to think about — actor (from the
[resolver chain](actor.md)), timestamp, request id, client IP, and the global kill-switch:

| Method | Payload argument |
| --- | --- |
| `created($class, $id, array $state, ?$label, ?$root, array $metadata)` | full state |
| `updated($class, $id, array $changes, ?$label, ?$root, array $metadata)` | `{field: {before, after}}` |
| `deleted($class, $id, array $stateAtDelete, ?$label, ?$root, array $metadata)` | full state at delete |
| `record(AuditAction $action, …)` | the generic form the other three call |

Use the same payload shapes automatic capture uses. That is the point: a reader of the trail should
not have to know which path wrote a diff, and `ManualLoggerTest` asserts it by capturing a creation,
replaying the same payload through the logger, and comparing the two rows column by column (minus
the identifier and the timestamp). Diverge and every consumer of the trail has to learn two formats.

One column pair genuinely does differ: the logger fills `request_id` and `ip` from the ambient
request context, and automatic capture never reads it — captured rows leave both null. See
[architecture.md](architecture.md#the-capture-pipeline-end-to-end).

`root` takes an `AuditScopeRef`, so a manual entry lands in the same aggregate feed as the captured
ones:

```php
use Th3Mouk\AuditTrail\Model\AuditScopeRef;

$this->audit->updated(
    Answer::class,
    $answerId,
    ['score' => ['before' => 3, 'after' => null]],
    'Q7 — energy mix',
    AuditScopeRef::of('questionnaire', $questionnaireId, 'Cooperl 2026'),
    ['origin' => 'dql', 'migration' => 'Version20260729120000'],
);
```

An empty payload array is stored as `null`, not as `{}`.

## It joins your transaction

`AuditLoggerInterface` does **not** flush. It persists the row and lets your own `flush()` write it,
so a manual entry rolls back with the work it describes exactly like a captured one:

```php
$this->em->wrapInTransaction(function () use ($id): void {
    $this->em->createQuery('DELETE FROM App\Entity\Token t WHERE t.id = :id')
        ->setParameter('id', $id)->execute();

    $this->audit->deleted(Token::class, $id);
});
```

If nothing ever flushes after your call, nothing is recorded. That is the same contract as
`persist()`.

## Forcing the actor

Imports, migrations and console commands usually know who they act for better than any resolver can.
`withActor()` returns a logger that attributes everything it records to one principal:

```php
use Th3Mouk\AuditTrail\AuditLogger;
use Th3Mouk\AuditTrail\Model\Actor;

public function __construct(private AuditLogger $audit) {}   // concrete class: withActor() lives there

$importer = $this->audit->withActor(Actor::of('erp-nightly', 'import', 'Nightly ERP import'));

foreach ($rows as $row) {
    $importer->created(Product::class, $row['id'], $row);
}
```

## The honest advice: prefer per-row `remove()`

Most bulk operations exist because someone reached for DQL out of habit, not because the volume
demanded it. When the volume is small, refactoring the bulk statement back into ORM operations gives
you the audit trail for free — with the real diff, the real label, the real root, and no second code
path to keep in sync.

**Bulk delete, logged by hand** — two statements, two sources of truth, and the state you record is
whatever you remembered to select:

```php
$expired = $this->tokens->findExpiredIdsAndLabels();          // extra query, hand-written

$this->em->createQuery('DELETE FROM App\Entity\Token t WHERE t.expiresAt < :now')
    ->setParameter('now', $now)
    ->execute();

foreach ($expired as ['id' => $id, 'label' => $label]) {
    $this->audit->deleted(Token::class, $id, ['label' => $label], $label);
}
```

**The same thing as removes** — one source of truth, full state at delete, root and label resolved by
the same rules as everywhere else, atomic without you thinking about it:

```php
foreach ($this->tokens->findExpired($now) as $token) {
    $this->em->remove($token);
}

$this->em->flush();
```

Reach for manual logging when the refactor is genuinely not available — hundreds of thousands of
rows, a raw `INSERT … SELECT`, a migration's data step, a side effect in another system. Reach for it
knowingly, not by default.

Two more levers worth knowing before you hand-roll anything:

- a bulk *import* that would flood the trail can be silenced instead of instrumented —
  [extending.md](extending.md#capture-gate-silencing-an-import);
- a high-churn technical column can be excluded per-property with `#[NotAuditable]` —
  [attributes.md](attributes.md#field-policy).
