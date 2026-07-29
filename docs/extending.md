# Extending

Every opinionated default is replaceable. Two mechanisms, and the choice is not yours to make — it
follows from whether the seam is a *chain* or a *single answer*:

| Seam | Interface | Mechanism |
| --- | --- | --- |
| [Actor resolver](#actor-resolver) | `Actor\ActorResolverInterface` | tag `audit_trail.actor_resolver` (`priority`) |
| [Capture gate](#capture-gate-silencing-an-import) | `Capture\CaptureGateInterface` | tag `audit_trail.capture_gate` (`priority`) |
| [Value serializer](#value-serializer-a-value-object) | `Capture\ValueSerializerInterface` | tag `audit_trail.value_serializer` (`priority`) |
| [Action resolver](#action-resolver-an-update-that-is-really-something-else) | `Capture\ActionResolverInterface` | tag `audit_trail.action_resolver` (`priority`) |
| [Field exclusion](#field-exclusion-a-change-that-does-not-reach-the-column) | `Capture\FieldExclusionInterface` | tag `audit_trail.field_exclusion` |
| [Label resolver](#label-resolver) | `Capture\LabelResolverInterface` | decorate the alias |
| [Scope resolver](#scope-resolver) | `Capture\ScopeResolverInterface` | decorate the alias |
| [Storage](#storage) | `Storage\AuditStorageInterface` | decorate or replace the alias |
| Manual logging | `AuditLoggerInterface` | inject it — [manual-logging.md](manual-logging.md) |

All of them are **public aliases**, so they are autowirable and decoratable from an application
without a fork. `StandaloneBundleTest` asserts that for the original seven.

Two rules apply to everything on this page, because during automatic capture all of it runs inside
`onFlush` — storage and the actor chain included: **no queries, and never call `flush()`**. (The
manual logger calls storage and the actor chain outside a flush as well, so both have to be correct
in either position.) See [architecture.md](architecture.md#the-two-hard-invariants).

---

## Actor resolver

A chain, highest priority first; the first non-null answer wins. See [actor.md](actor.md) for the
full picture.

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Model\Actor;

#[AutoconfigureTag('audit_trail.actor_resolver', ['priority' => 100])]
final readonly class MessengerActorResolver implements ActorResolverInterface
{
    public function __construct(private ActorStampHolder $holder) {}

    public function resolve(): ?Actor
    {
        $stamp = $this->holder->current();

        return null === $stamp ? null : Actor::of($stamp->id, 'worker', $stamp->label);
    }
}
```

The shipped `SecurityTokenActorResolver` sits at `-100`, so anything above it takes precedence.

---

## Label resolver

One answer, so it is a decoration. The default reads `#[AuditLabel]`, then `__toString()`, then gives
up; decorate it to centralise a domain rule — translated names, formatted references, a tenant
prefix — instead of scattering attributes.

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Th3Mouk\AuditTrail\Capture\LabelResolverInterface;

#[AsDecorator(LabelResolverInterface::class)]
final readonly class TranslatedLabelResolver implements LabelResolverInterface
{
    public function __construct(
        private LabelResolverInterface $inner,
        private TranslatorInterface $translator,
    ) {}

    public function resolve(object $entity): ?string
    {
        if ($entity instanceof Product) {
            return $this->translator->trans($entity->getNameKey());
        }

        return $this->inner->resolve($entity);
    }
}
```

Delegate to `$inner` for everything you do not handle, and return `null` rather than loading
anything: a label is worth a snapshot, not a query.

---

## Scope resolver

Also one answer. Use this when the aggregate rule is domain-wide; use
[`AuditScopeProviderInterface`](aggregate-history.md#the-escape-hatch) when it is per-entity.

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Th3Mouk\AuditTrail\Capture\ScopeResolverInterface;
use Th3Mouk\AuditTrail\Model\AuditRef;

#[AsDecorator(ScopeResolverInterface::class)]
final readonly class TenantScopeResolver implements ScopeResolverInterface
{
    public function __construct(private ScopeResolverInterface $inner) {}

    public function resolve(object $entity): ?AuditRef
    {
        if ($entity instanceof TenantOwned) {
            $tenantId = $entity->tenantId();          // already in memory, no association walk

            return null === $tenantId ? null : AuditRef::of(Tenant::class, $tenantId);
        }

        return $this->inner->resolve($entity);
    }

    public function resolveType(object $entity): ?string
    {
        return $entity instanceof TenantOwned ? 'tenant' : $this->inner->resolveType($entity);
    }
}
```

Keep `resolve()` and `resolveType()` in agreement — they are called separately, and a root with the
wrong `root_type` silently lands in the wrong feed.

---

## Value serializer: a value object

A priority chain; the first serializer that `supports()` a value wins. The shipped chain is:

| Priority | Handles |
| --- | --- |
| `200` | scalars and `null` |
| `150` | `\DateTimeInterface` → ISO-8601 |
| `100` | enums → `->value` / `->name` |
| `50` | mapped entities → `AuditRef` |
| `-100` | any other `\Stringable` → `(string)` |

Anything nothing claims is excluded from the payload and logged once per type at warning level. Teach
it your value object:

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;

#[AutoconfigureTag('audit_trail.value_serializer', ['priority' => 300])]
final readonly class MoneyValueSerializer implements ValueSerializerInterface
{
    public function supports(mixed $value): bool
    {
        return $value instanceof Money;
    }

    public function serialize(mixed $value): mixed
    {
        \assert($value instanceof Money);

        return ['amount' => $value->minorUnits(), 'currency' => $value->currency()->code()];
    }
}
```

Any priority above `-100` beats the last-resort stringable handler, which matters if your value
object is also `\Stringable`: left to that handler, a `Money` would be flattened to `"12.30 EUR"` and
you could never compare two entries. Registering above `50` also lets you override how a specific
mapped entity is captured.

Same trick for redaction that attributes cannot express — an email masked everywhere it appears,
whatever the field name:

```php
public function supports(mixed $value): bool
{
    return $value instanceof EmailAddress;
}

public function serialize(mixed $value): mixed
{
    return $value->obfuscated();      // a***@example.com
}
```

---

## Action resolver: an update that is really something else

Doctrine knows three shapes — insert, update, delete — and a library sitting on top of it can turn
one into another before capture sees it. The archetype is a logical delete: the row is *updated*, but
what happened is a deletion, and the change set is the only evidence left.

A priority chain, but **not** a unanimous one: the first non-null answer wins and the rest are never
asked. `null` means "no opinion", which is what a resolver returns for every entity it knows nothing
about; when nobody answers, capture records an `update` as before. Consulted for scheduled updates
only — insertions and deletions are already unambiguous.

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Th3Mouk\AuditTrail\Capture\ActionResolverInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

#[AutoconfigureTag('audit_trail.action_resolver', ['priority' => 100])]
final readonly class ArchivedIsADeletion implements ActionResolverInterface
{
    public function resolveAction(object $entity, array $changeSet): ?AuditAction
    {
        if (!$entity instanceof Invoice || !isset($changeSet['archivedAt'])) {
            return null;
        }

        return null === $changeSet['archivedAt'][0] && null !== $changeSet['archivedAt'][1]
            ? AuditAction::Delete
            : null;
    }
}
```

Two things follow from where it is called, and they are the point:

- an entry resolved to `Delete` carries the entity's **full state** instead of a diff, exactly like a
  deletion Doctrine scheduled itself, and obeys `capture.state_on_delete`;
- the capture gates are asked about the **resolved** action, so `CascadeSuppressionGate` treats it
  like any other deletion.

Returning `AuditAction::Create` for an update is possible and almost never what you want — the entry
would claim the row was created in this flush.

The shipped implementation is the Gedmo bridge's
[`SoftDeleteableActionResolver`](bridges/gedmo.md#softdeleteableactionresolver).

---

## Field exclusion: a change that does not reach the column

The mirror image. Capture reads change sets before other `onFlush` listeners rewrite them, so it can
see a value that is about to be taken back out again — Gedmo's Translatable does exactly that for a
non-default locale. Reporting it puts a column change in the trail that never reaches the table: a
*wrong* entry, not a missing one, and the worse of the two.

Every contributor is consulted and the results are merged, so an exclusion can only ever remove a
field from an entry, never add one — which makes adding one safe. Applies to **updates only**: an
insertion writes the entity's own columns whatever happens afterwards, and a deletion has no change
set to trim.

```php
namespace App\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Th3Mouk\AuditTrail\Capture\FieldExclusionInterface;

#[AutoconfigureTag('audit_trail.field_exclusion')]
final readonly class SearchVectorIsRebuiltDownstream implements FieldExclusionInterface
{
    public function excludedFields(EntityManagerInterface $entityManager, object $entity): array
    {
        return $entity instanceof Article ? ['searchVector'] : [];
    }
}
```

The row-trigger rule runs *after* the subtraction, so an entity whose only change was excluded
produces no entry at all rather than an empty one. Note the difference from `#[NotAuditable]`, which
is the right tool when a field should never be recorded for anyone: an exclusion is per entity and
per flush, and is meant for "this change is not what it looks like", not for "this field is noise".

The shipped implementation is the Gedmo bridge's `TranslationFieldExclusion`, which delegates to
[`TranslationAuditListener::translationOnlyFields()`](bridges/gedmo.md#the-consequence-of-running-before-gedmo-and-what-is-done-about-it).

---

## Capture gate: silencing an import

All gates must agree — a single `false` vetoes the entry. Veto semantics mean a gate can only ever
*remove* noise, never smuggle something in, so adding one is safe.

Shipped gates: `EnabledGate` (priority `200`, the kill-switch) and `CascadeSuppressionGate`
(priority `100`, drops children deleted along with their root).

### The cheap way: pause at runtime

```php
use Th3Mouk\AuditTrail\Capture\Gate\EnabledGate;

public function __construct(private EnabledGate $gate) {}

public function import(iterable $rows): void
{
    $this->gate->pause();

    try {
        foreach ($rows as $row) { /* persist */ }
        $this->em->flush();
    } finally {
        $this->gate->resume();
    }
}
```

The `finally` is not optional. `EnabledGate` implements `ResetInterface`, but the bundle registers
it without the `kernel.reset` tag, so Symfony's service resetter never calls `reset()` on it: in a
long-running worker a job that pauses and then throws leaves capture silenced for every message that
follows. Pause and resume in the same `try`/`finally`, or tag the service yourself.

### The declarative way: your own gate

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

#[AutoconfigureTag('audit_trail.capture_gate', ['priority' => 50])]
final readonly class SkipImportedDraftsGate implements CaptureGateInterface
{
    public function shouldCapture(object $entity, AuditAction $action): bool
    {
        return !($entity instanceof Product && $entity->isImportDraft() && AuditAction::Create === $action);
    }
}
```

A gate is consulted per entity per flush, so keep it a memory-only predicate: no queries, no lazy
association reads.

---

## Storage

The default `DoctrineAuditStorage` persists the entry and — when it is called from inside a flush —
computes its change set so the row joins the **very transaction** that produced the change. A
rolled-back change takes its audit entry with it.

**Replacing storage trades that away.** A queue, a log shipper, an HTTP sink or a second database
cannot be in your transaction, so you inherit two failure modes the default does not have: a change
that commits without its entry, and an entry that describes a change that rolled back. Both are
silent. That is an acceptable trade for some systems and unacceptable for others — make it
deliberately.

**Decorating** keeps atomicity and is usually what you want. Enriching every entry, for example —
`AuditEntry::withMetadata()` merges into what is already there:

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;

#[AsDecorator(AuditStorageInterface::class)]
final readonly class StampTraceIdOnEntries implements AuditStorageInterface
{
    public function __construct(
        private AuditStorageInterface $inner,
        private TraceContext $trace,
    ) {}

    public function store(AuditEntry $entry): void
    {
        $this->inner->store($entry->withMetadata(['trace_id' => $this->trace->id()]));
    }
}
```

**A second sink alongside the durable one** is also a decoration — the transactional write stays, the
extra hop is best effort:

```php
#[AsDecorator(AuditStorageInterface::class)]
final readonly class AlsoShipToSiem implements AuditStorageInterface
{
    public function __construct(
        private AuditStorageInterface $inner,
        private MessageBusInterface $bus,
    ) {}

    public function store(AuditEntry $entry): void
    {
        $this->inner->store($entry);                       // still atomic
        $this->bus->dispatch(new AuditEntryRecorded($entry));  // best effort
    }
}
```

If you dispatch to a bus from inside a flush, use a transport that only sends after commit
(Messenger's `DoctrineTransactionMiddleware` / `dispatch_after_current_bus`), or you will announce
changes that never happened.

Whatever you write: **`store()` must never call `flush()`.** Flushing from inside a flush corrupts
the unit of work; flushing from outside one decides on the caller's behalf when their transaction
ends.
