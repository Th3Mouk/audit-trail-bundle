# Gedmo bridge

Two pieces, both auto-detected from `gedmo/doctrine-extensions`:

```php
// config/packages/audit_trail.php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('audit_trail', [
        'bridges' => ['gedmo' => [
            'enabled' => null,           // null = auto-detect the package
            'translatable' => true,      // translation auditing, and the field trimming with it
            'soft_deleteable' => true,   // a logical delete reads as a delete
            'listener_priority' => 1,    // must stay above Gedmo's own listeners
        ]],
    ]);
};
```

Every option has an effect. `enabled: false` switches the bridge off wholesale; `translatable` and
`soft_deleteable` each remove their own half and leave the other one alone; `listener_priority`
places the translation listener relative to Gedmo's — see [Listener ordering](#listener-ordering),
and leave it alone unless your Gedmo listeners are not at priority `0`.

Verified against `gedmo/doctrine-extensions` **3.22**. Nothing here queries during `onFlush` — no
`findTranslation()`, no `loadTranslations()`, no lazy association reads.

Both halves reach the capture pipeline through generic seams, so the core never names Gedmo:
`Capture\ActionResolverInterface` (tag `audit_trail.action_resolver`) reclassifies an update, and
`Capture\FieldExclusionInterface` (tag `audit_trail.field_exclusion`) removes fields from one. They
are documented as extension points in [extending.md](../extending.md).

---

## Translation auditing

### What it records, and where

A translation is a change to an entity's **content**, not a change to a side table nobody asked
about. So entries are recorded **against the translated entity**: they appear in that entity's own
history and in the [aggregate feed](../aggregate-history.md) of its audit root, next to every other
change to the same thing.

One entry per `(entity, locale)` pair per flush. The locale rides inside each field so two locales of
the same field in one flush cannot overwrite each other:

```json
{
  "action": "update",
  "entity_type": "page",
  "entity_id": "0197f1c2-...",
  "entity_label": "Analytical Engine",
  "changes": {
    "title": { "locale": "fr", "before": "Ancien titre", "after": "Nouveau titre" },
    "body":  { "locale": "fr", "before": "…",            "after": "…" }
  },
  "metadata": { "locale": "fr", "translation_class": "Gedmo\\Translatable\\Entity\\Translation" }
}
```

Field policy applies exactly as elsewhere: a `#[NotAuditable]` translated field is dropped, an
`#[AuditMasked]` one yields `{"locale": "fr", "before": "********", "after": "********"}`. See
[attributes.md](../attributes.md#field-policy).

Writes in the **default locale** are intentionally not recorded here — they land in the entity's own
columns and the ordinary capture listener already records them. Recording both would be noise.

### The two Gedmo persistence paths, and exactly which are covered

Inside `TranslatableListener::onFlush()`, a translation row is written one of two ways:

| | How | Visible to |
| --- | --- | --- |
| **Path A** | through the UnitOfWork (`persist()` + `computeChangeSet()`, or `recomputeSingleObjectChangeset()`) | any listener that runs **after** Gedmo |
| **Path B** | straight through the DBAL — `$connection->insert($table, $data)` from `postPersist()`, used when the parent entity has no identifier yet | nothing; no UnitOfWork, no events, no change set |

Neither is a viable capture source on its own: path B is invisible, and by the time path A exists,
Gedmo has already reverted the translatable fields out of the source change set. So the bridge audits
the **source entity's change set** instead — both paths are produced from it, and it is readable
before Gedmo runs.

That gives this coverage, honestly:

| Case | Covered |
| --- | --- |
| update in a non-default locale | **yes** |
| insert of an entity with a pre-assigned identifier (UUID), non-default locale | **yes** |
| insert of an entity whose identifier the database generates (`IDENTITY`, sequence) | **no** — see below |
| a translation object the application persists itself | **yes** |
| personal translations (`AbstractPersonalTranslation`) | **yes** |
| write in the default locale | not here — recorded by the ordinary listener as a field change |
| locale switch alone (locale property changed, translatable fields untouched) | **no** — the `before` value would need a `SELECT` |
| translations deleted with their entity (Gedmo's DQL delete) | **no** — the entity's own `delete` entry stands for it |
| a custom translation entity extending neither Gedmo superclass | **partly** — covered via the source change set, not via the translation object |

Every **yes** in that table holds at Gedmo's own default priority of `0`, with no configuration:
`bridges.gedmo.listener_priority` defaults to `1`, one notch above, which is what puts this listener
in front of the rewriting. It is the one thing the table depends on, and the failure is silent — put
this listener at or below Gedmo's priority and every **yes** above becomes a no, with no error and
nothing in the log. If your Gedmo listeners are not at `0`, raise it to match:
[Listener ordering](#listener-ordering).

**Why the database-generated identifier case cannot be covered.** Path B happens exactly when the
source entity has no identifier at `onFlush` time — which is exactly when no `onFlush`-based audit can
name the row it is talking about. It is the same limitation the entity's own `create` entry has (see
[architecture.md](../architecture.md#the-atomicity-guarantee)). The listener detects the case and logs
it once per class at warning level rather than dropping it silently:

> Audit trail cannot record translated content for "…": the entity still has no identifier when
> onFlush runs …

**Assigning identifiers in the constructor (UUID v7, as the bundle's own `AuditLog` does) removes that
case entirely** — Gedmo then takes path A. It does not change the other three `no` rows above, which
have nothing to do with identifiers.

Alternatives were considered and rejected: reading Gedmo's private `$pendingTranslationInserts` by
reflection (`postPersist` is past the point where a new entity can join the flush), decorating
`TranslatableAdapter` (no seam — Gedmo instantiates it itself), a DBAL middleware sniffing INSERTs
(no actor context, mid-flush driver writes), and a `postFlush` fixup (breaks the atomicity guarantee).

### One more honest note

A translation whose **source entity is not loaded** — a foreign-key translation whose parent is absent
from the identity map — is skipped and logged once per class. Capture gates cannot be evaluated
without the object, and loading it would be a query. Recording past a gate that might have vetoed the
entry is the worse failure, so the bridge fails closed.

---

## Soft deletes

Gedmo's `SoftDeleteableListener` cancels the removal and rewrites it into an `UPDATE` of the
configured date field. A logical delete therefore arrives at capture as a change on a date column,
and `SoftDeleteableActionResolver` is what turns it back into a `delete`.

### What the trail records

| You wrote | Capture runs before Gedmo's `SoftDeleteableListener` | Capture runs after it |
| --- | --- | --- |
| `$em->remove($page)` | `delete`, with the entity's full state — the entity is still in `getScheduledEntityDeletions()` | **nothing at all** |
| `$page->setDeletedAt(...)` by hand, no `remove()` | `delete`, with the entity's full state | `delete`, with the entity's full state |
| `$page->restore()` — the date back to `null` | `update`, `deletedAt: <date> → null` | same |
| the date moved to another date | `update`, `deletedAt: <old> → <new>` | same |

Row two is the common one in real applications, and it is the one the resolver exists for: there was
never a deletion for Doctrine to schedule, so no listener ordering reaches it. Row one still does
need [ordering](#listener-ordering) — and note that "nothing at all" is unconditional, whether or
not the entity was dirty for another reason. Once Gedmo has rewritten the removal the entity is in
neither `entityDeletions` nor `entityUpdates`, so there is no change set left for the resolver to
read.

A restore is deliberately an `update` and not a third action: `AuditAction` has exactly
`create` / `update` / `delete`, and a `delete` entry followed by an `update` clearing the date is
already unambiguous. Adding a case would change what is stored, what the feed filters on, and every
consumer's `match`.

### `SoftDeleteableActionResolver`

The class that tells those transitions apart:

| `deletedAt` transition | `resolveAction()` returns | `resolveTransition()` returns |
| --- | --- | --- |
| `null` → a date | `AuditAction::Delete` | `'delete'` |
| a date → `null` (restore) | `AuditAction::Update` | `'restore'` |
| a date → another date | `null` | `null` |
| field absent from the change set | `null` | `null` |

It reaches capture as an `ActionResolverInterface`, tagged `audit_trail.action_resolver` — the seam
that lets a scheduled `UPDATE` be recorded as something else. `AuditLogListener` consults the chain
for every scheduled update and falls back to `AuditAction::Update` when nobody has an opinion, so
nothing changes for an entity that is not soft deleteable.

Two details of that, both intentional:

- a reclassified deletion carries **the entity's state**, exactly like a deletion Doctrine
  scheduled itself — `capture.state_on_delete` governs it the same way. A two-field diff on a date
  column is not a legible account of a deletion;
- the [capture gates](../extending.md#capture-gate-silencing-an-import) are asked about the
  *resolved* action, so `capture.suppress_cascade_children` treats a logical delete like any other
  deletion.

`isLogicalDelete()`, `isRestore()`, `isSoftDeleteable()` and `deletedAtField()` stay public for
application code that wants the same reading elsewhere; the class has no dependencies, memoises per
class, and reads nothing but the change set it is handed, so it is safe to call from `onFlush`.

Set `soft_deleteable: false` and the resolver is not registered at all: every logical delete goes
back to being an ordinary `update` on the date column.

Limits of the class itself:

- configuration is read from the `#[Gedmo\SoftDeleteable]` **attribute**, walking parents so a mapped
  superclass is honoured. Gedmo XML/YAML mapping and legacy docblock annotations are not consulted.
- `timeAware`: a `null` → *future* date transition still reads as `delete`. The transition records
  when the deletion date was set, which may precede its effect.
- `hardDelete` needs no handling — Gedmo skips its rewrite and the entity stays a real deletion.

---

## Listener ordering

Two priorities, one per listener, because the two have different neighbours:

```
higher priority
   │  Timestampable, Blameable        ← must run BEFORE capture,
   │                                     so their stamps are in the change set
   ├─ audit capture                       audit_trail.listener_priority          (default 0)
   ├─ TranslationAuditListener            bridges.gedmo.listener_priority        (default 1)
   │  Gedmo Translatable              ← must run AFTER both of ours,
   ▼  Gedmo SoftDeleteable               so its change-set rewriting is not what they read
lower priority
```

**Gedmo registers all of its listeners at priority `0`** — it declares none, so whatever wires them
lands on Symfony's default. Every default here is chosen against that number.

### The translation listener: nothing to do

`bridges.gedmo.listener_priority` defaults to `1`, one notch above Gedmo, which is all this listener
needs — it does not care about stampers. Change it only if your Gedmo listeners are *not* at `0`,
and keep it strictly above them. At or below Gedmo's priority, translation auditing records nothing
and reports nothing: the change set it reads has already been reverted.

The bundle's own test kernels register Gedmo at `0` rather than at some convenient low number, so the
suite exercises the ordering an installation actually has; `GedmoBridgeWiringTest` asserts the
default as a number, because a priority on the wrong side of Gedmo cannot be caught by a
behavioural test that has already tuned it.

### Capture: still one choice to make

**One priority cannot satisfy both sides while every Gedmo listener shares priority 0.** Stampers
(Timestampable, Blameable) and rewriters (Translatable, SoftDeleteable) are all Gedmo `onFlush`
listeners at `0`: raise `listener_priority` above them and `$em->remove()` on a soft-deleteable
entity reads as a `delete`, but you lose the stamps from your diffs; leave it at the default and the
ordering against Gedmo comes down to registration order, which nothing guarantees.

Two ways out. Give Gedmo's own listeners explicit priorities so the groups separate, and leave
capture between them:

```php
// config/packages/audit_trail.php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('audit_trail', [
        'listener_priority' => 64,
    ]);
};
```

```php
// the stampers above capture, the rewriters left at Gedmo's default
$blameable->addTag('doctrine.event_listener', ['event' => 'onFlush', 'priority' => 128]);
$timestampable->addTag('doctrine.event_listener', ['event' => 'onFlush', 'priority' => 128]);
```

That is the arrangement `GedmoKernel` and `GedmoBridgeKernel` use, and it is what lets the suite
assert both guarantees at once.

Or, if you integrate Gedmo through a bundle that owns the registration and will not let you set
priorities: raise `listener_priority` above `0` and accept losing stamps from your diffs, or leave
it and accept that `$em->remove()` on a soft-deleteable entity may record nothing. What you no
longer have to trade away is translation auditing or the hand-made soft delete — those work at any
capture priority.

### Why it matters, concretely

| Situation | Wrong ordering gives | Correct ordering gives |
| --- | --- | --- |
| a Blameable/Timestampable stamp | capture runs first and the stamp is missing from the diff | `updatedBy: alice → bob` is part of the entry |
| a translated field in a non-default locale | the *translation* listener runs after Gedmo, which has already reverted the field — nothing recorded | the translation entry, with `before` and `after` |
| `$em->remove()` on a soft-deleteable entity | capture runs after Gedmo: the entity is in neither `entityDeletions` nor `entityUpdates` — **nothing at all is recorded**, dirty or clean | a `delete` entry with the entity's full state |

That last row is the one that bites, and `SoftDeleteableActionResolver` cannot fix it — there is no
change set left for it to inspect. Only ordering does: capture has to run before Gedmo's
`SoftDeleteableListener`. A hand-made soft delete is unaffected, at any priority.

### The consequence of running before Gedmo, and what is done about it

For a non-default locale Gedmo *reverts* translatable fields out of the entity's own change set
after capture has read them. Left alone, the entity's ordinary `update` entry would claim a column
change that never reaches the table — a wrong entry sitting next to a translation entry
contradicting it.

`TranslationFieldExclusion` prevents that: it hands
`TranslationAuditListener::translationOnlyFields()` to capture as a `FieldExclusionInterface`, and
`AuditLogListener` subtracts those fields from the entity's own UPDATE payload. Only the diverted
fields go — a plain column changed in the same flush keeps its entry — and if nothing else changed,
the entity produces no ordinary entry at all rather than an empty one. Insertions are untouched: on
insert Gedmo writes the value to the entity's own columns as well.

The exclusion belongs to the `translatable` option. Set it to `false` and you lose both halves: no
translation entries, **and** no trimming — an entity updated in a non-default locale will then show
those fields in its ordinary `update` entry, asserting a column change Gedmo reverts. There is no
setting that keeps the trimming without the translation entries, and that is deliberate: the
trimming is only truthful because the content is recorded elsewhere.
