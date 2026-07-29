# Gedmo bridge — implementation notes

Maintainer notes, not user documentation. Read before touching either class: both encode
behaviour of `gedmo/doctrine-extensions` internals (verified against **3.22**), and both are
built so the bundle boots with Gedmo absent.

| File | Role |
| --- | --- |
| `SoftDeleteableActionResolver.php` | maps a Gedmo/hand-made logical delete onto `AuditAction::Delete` (and a restore onto `Update`) — an `ActionResolverInterface` |
| `TranslationAuditListener.php` | records translated content as an entry on the translated entity |
| `TranslationFieldExclusion.php` | hands the listener's `translationOnlyFields()` to capture as a `FieldExclusionInterface` |

All three obey the bundle-wide rule: **no query during `onFlush`**. Nothing here reads the
database, initialises a lazy association, or calls a Gedmo method that emits DQL
(`findTranslation()`, `loadTranslations()`, `removeAssociatedTranslations()` are all off-limits).

**The core knows nothing about Gedmo, and must not learn.** Both ways into `AuditLogListener` are
tagged interfaces under `src/Capture/` — `ActionResolverInterface` (`audit_trail.action_resolver`,
first non-null answer wins) and `FieldExclusionInterface` (`audit_trail.field_exclusion`, answers
merged). `StandaloneBundleTest` fails the moment a Gedmo symbol reaches the core, so this is a
structural rule rather than a convention.

---

## SoftDeleteableActionResolver

### What Gedmo does

`SoftDeleteableListener::onFlush()` (vendor `src/SoftDeleteable/SoftDeleteableListener.php`
104-156) takes an entity scheduled for deletion and, unless the `hardDelete` shortcut applies
(112-114):

```php
$meta->setFieldValue($object, $config['fieldName'], $date);
$om->persist($object);                                   // un-schedules the deletion
$uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
$uow->scheduleExtraUpdate($object, [...]);               // the actual UPDATE
```

`persist()` on a REMOVED entity resurrects it, and `propertyChanged()` writes
`[$oldValue, $date]` into `UnitOfWork::$entityChangeSets` (vendor `doctrine/orm`
`UnitOfWork::propertyChanged()`). A logical delete therefore reaches an audit listener as a
change set entry on a date column — never as a deletion.

### Ordering matters, and this resolver is needed in every ordering

| Situation | Without the resolver | With it |
| --- | --- | --- |
| `remove()` + audit listener runs **before** Gedmo | correct `Delete` (entity still in `getScheduledEntityDeletions()`) | unchanged — the resolver only inspects update change sets |
| `remove()` + audit listener runs **after** Gedmo | **nothing at all**, dirty or clean | still nothing; only `audit_trail.listener_priority` above Gedmo's listener fixes this |
| application soft-deletes by hand (`setDeletedAt(...)`, no `remove()`) | `Update` | `Delete` |

Row two used to be written here as two rows, claiming that an entity dirty for another reason
came out as an `Update` with a `deletedAt` diff. It does not, and it is worth knowing why before
someone reinstates the claim: `computeChangeSets()` skips entities in `entityDeletions`, so a
dirty *and* removed entity never enters `entityUpdates` in the first place; Gedmo's `persist()`
only moves it out of `entityDeletions`, `propertyChanged()` writes a change set and schedules a
dirty check, and `scheduleExtraUpdate()` writes to `extraUpdates`. None of those three is
`entityUpdates`, which is the only list capture reads. Dirty or clean, there is nothing left for
the resolver to inspect.

The last row is the common one in real applications and no ordering trick catches it, which is
the main reason this class exists.

### Wiring

Stateless, no dependencies, memoises per class. Registered from `bridge_gedmo.php` with the
`audit_trail.action_resolver` tag, and removed again by `AuditTrailExtension` when
`bridges.gedmo.soft_deleteable` is false. **Single call site**, in
`AuditLogListener::collectUpdates()`, through the chain rather than through this class:

```php
$changeSet = $this->fieldChangesOf($unitOfWork, $entity);
$action = $this->actionResolver->resolveAction($entity, $changeSet) ?? AuditAction::Update;
```

Two consequences of that call site, both deliberate:

* the capture gates are asked about the **resolved** action, so `CascadeSuppressionGate` treats a
  logical delete like any other deletion;
* when the action comes back `Delete`, the entry carries `serializeState()` rather than the diff —
  a two-field diff on a date column is not a legible account of a deletion. The row-trigger rule
  is therefore not consulted for that entry: a soft delete whose date column is `#[NotAuditable]`
  is still recorded, because what happened was a deletion, not a column change.

`resolveTransition()` / `isLogicalDelete()` / `isRestore()` stay public for application code and
are not used by the wiring. A restore deliberately reads as an ordinary `update` carrying
`deletedAt: <date> → null`: there is no `restore` case in `AuditAction`, and adding one would
change the stored vocabulary, the API filters and every consumer's exhaustive `match`. The pair
`delete` / `update`-clearing-the-date is already unambiguous.

### Deliberate limits

* Configuration is read from the `#[Gedmo\SoftDeleteable]` attribute, walking parents so a
  mapped superclass or a base entity is honoured. Gedmo XML/YAML extension mapping and legacy
  `@Gedmo\SoftDeleteable` docblock annotations are **not** consulted — supporting them means
  reaching for `SoftDeleteableListener::getConfiguration($om, $class)`, which drags an
  `ObjectManager` into a currently dependency-free service. Do that only if someone asks.
* `timeAware`: a `null → future date` transition is still reported as `Delete`. The entry
  records the moment the deletion date was *set*, which may precede its effect.
* `hardDelete` needs no handling: Gedmo skips its rewrite and the entity stays a real deletion.
* Only `null ↔ non-null` transitions are decisions. Moving a date to another date is an
  ordinary update.

---

## TranslationAuditListener

### The two write paths, and why the UnitOfWork alone is not enough

Inside `TranslatableListener::onFlush()` → `handleTranslatableObjectUpdate()` (vendor
`src/Translatable/TranslatableListener.php` 638-792) a translation row is written in one of two
ways:

* **Path A — through the UnitOfWork** (741-746): `recomputeSingleObjectChangeset()` for a
  translation the application persisted itself, otherwise `$om->persist($translation)` +
  `$uow->computeChangeSet(...)`. Visible to any listener that looks at scheduled insertions —
  *but only after Gedmo has run*, which is too late for a listener that must run before Gedmo.
* **Path B — straight through the DBAL** (735-738): when the parent is being inserted and has no
  identifier yet, the translation is parked in `$pendingTranslationInserts`, then flushed in
  `postPersist()` (470-495) via `TranslatableAdapter::insertTranslationRecord()` — vendor
  `src/Translatable/Mapping/Event/Adapter/ORM.php` 191-207:
  `$em->getConnection()->insert($table, $data)`. No UnitOfWork, no events, no change set.

So a UnitOfWork-only audit misses path B entirely, and an audit that waits for Gedmo to run
cannot read the source change set any more (Gedmo reverts translatable fields at 762-791).

### The design

Audit the **source entity's change set**, not the translation rows. Both paths are produced from
that same change set, and it is readable before Gedmo runs. Concretely, per flush:

1. every scheduled insertion/update that is `#[Auditable]` and translatable
   (`TranslatableListener::getConfiguration()` — public, cached, driver-agnostic, no query);
2. the effective locale: the entity's `#[Gedmo\Locale]`/`#[Gedmo\Language]` property when
   initialised (read through our own reflection, with an `isInitialized()` guard — Gedmo's
   `getTranslatableLocale()` would raise on an uninitialised typed property), else
   `getListenerLocale()`;
3. default locale ⇒ skip: that write lands in the entity's own columns and `AuditLogListener`
   already records it;
4. per translatable field present in the change set, `FieldPolicyResolver` decides
   tracked / masked / ignored exactly as elsewhere, and `ValueSerializerInterface` renders the
   values;
5. plus any Gedmo translation object the application persisted itself, spotted in the UnitOfWork
   (`AbstractTranslation` / `AbstractPersonalTranslation`) and attributed back to its source via
   `getObject()` or `getObjectClass()` + `getForeignKey()` (+ `UnitOfWork::tryGetById()`, an
   identity-map lookup, no query);
6. one `AuditLoggerInterface::updated()` call per `(entity, locale)`, changes keyed by field with
   the locale inside each one, metadata `{locale, translation_class}`.

Grouping by locale rather than by field is what keeps two locales of the same field in one flush
from overwriting each other, since `changes` is keyed by field name.

### Coverage

| Case | Covered | Notes |
| --- | --- | --- |
| update in a non-default locale (path A) | yes | `before` is the previous translation content: `postLoad` made it the change set's old value |
| insert with a pre-assigned identifier (UUID), non-default locale | yes | Gedmo takes path A |
| insert with a generated identifier (IDENTITY/sequence) | **no** | see below |
| application persists a translation object itself | yes | path 5 above |
| personal translations (`AbstractPersonalTranslation`) | yes | path B never applies to them |
| write in the default locale | intentionally not | recorded by `AuditLogListener` as an ordinary field change; recording it twice would be noise, including under `persistDefaultLocaleTranslation` |
| locale switch alone (locale property changed, translatable fields untouched) | no | Gedmo may copy untouched fields into the new locale; the `before` value would need a `SELECT` |
| translations deleted with their entity (Gedmo DQL delete, 447-457) | no | the entity's own `Delete` entry stands for it; enumerating the rows needs a query |
| custom translation entity extending neither Gedmo superclass | partly | the source-change-set path still covers it; path 5 does not detect it |

**Path B is not covered, and it cannot be** — that is not a shortcut, it is the shape of the
problem. Path B happens exactly when the source entity has no identifier at `onFlush` time,
which is exactly when no `onFlush`-based audit can name the row it is talking about (the
entity's own `Create` entry has the same problem). The listener detects the case and logs it
once per class at warning level rather than dropping it silently. Assigning identifiers in the
constructor (UUID v7, as `AuditLog` itself does) removes the case entirely — Gedmo then takes
path A.

Mechanisms considered for path B and rejected:

* **read Gedmo's private `$pendingTranslationInserts`** (reflection) and record from
  `postPersist`: the pending list is private state with no accessor, and `postPersist` fires
  from inside `executeInserts()`, where new entities can no longer join the flush — an audit row
  written there would either be lost or need a second flush, breaking the atomicity the storage
  layer exists to guarantee.
* **intercept `TranslatableAdapter::insertTranslationRecord()`**: `MappedEventSubscriber::getEventAdapter()`
  instantiates the adapter itself; there is no seam to decorate. Substituting it means patching
  or forking Gedmo.
* **a DBAL middleware sniffing INSERTs into translation tables**: needs SQL/table-to-entity
  reconstruction, sees no actor context, and would write audit rows from inside the driver while
  the ORM is mid-flush.
* **`postFlush` fixup of a deferred entry**: forbidden by capture contract 1 (nothing persists
  after the flush; a rolled-back change must take its audit entry with it).

### Wiring

Constructor (all required except the last two):

```
AuditLoggerInterface, AuditableResolver, FieldPolicyResolver, ValueSerializerInterface,
EntityIdResolver, LabelResolverInterface, ScopeResolverInterface, CaptureGateInterface,
FlushState, ?TranslatableListener, ?LoggerInterface
```

* registered on `onFlush` from `bridge_gedmo.php` when `bridges.gedmo.enabled`, and removed again
  by `AuditTrailExtension` when `bridges.gedmo.translatable` is false; `Gedmo\Translatable\TranslatableListener`
  is injected `nullOnInvalid()`, so the class is inert if the service is missing;
* `FlushState` is marked around the recording, not decoration: a mark only lasts for the callback
  that made it, and `AuditLogListener` clears its own long before Doctrine's `commit()` returns.
  Whichever of the two runs second is still inside that flush. Recording unmarked leaves the audit
  row in a unit of work whose change sets are already computed, and the insert persister, binding
  its parameters from the change set alone, re-executes the previous row's INSERT: a duplicate
  primary key, not a missing row;
* the priority comes from **`bridges.gedmo.listener_priority`**, a separate option from
  `audit_trail.listener_priority` and rewritten onto the tag by `AuditTrailExtension` — Doctrine's
  listener pass reads `priority` as a plain integer, so a `%parameter%` in the service file would
  not survive. It defaults to `Configuration::GEDMO_LISTENER_PRIORITY + 1`, i.e. **1**, because
  Gedmo's own listeners land on Symfony's default of 0 and this one has to be *strictly* above
  them. Never hard-code it, and never put it at or below 0: at 0 the ordering falls to
  registration order, below 0 the listener reads an already-reverted change set and records
  nothing at all, with no error to show for it. That was a real shipped defect (`-100`), which is
  why `GedmoBridgeWiringTest` asserts the number and both test kernels register Gedmo at 0;
* it calls `AuditLoggerInterface::updated()`, so actor, timestamp, request id, client ip and the
  global kill switch all come for free, and the entry is stored in the same transaction.

`translationOnlyFields(EntityManagerInterface, object): array` is the second call site for
`AuditLogListener`, reached through `TranslationFieldExclusion` (a `FieldExclusionInterface`, tag
`audit_trail.field_exclusion`, same `translatable` option). For a non-default locale Gedmo reverts
those fields from the entity's change set (762-791), so an entry produced before Gedmo runs would
claim a column change that never happens; `AuditLogListener` subtracts them with `array_diff_key`
from the UPDATE payload only — on insert Gedmo writes the value to the entity's own columns too,
and a deletion has no change set to trim.

Order of the two seams inside `collectUpdates()` matters and is not interchangeable:
`resolveAction()` reads the **whole** change set — what happened is not a function of what gets
recorded, and an exclusion must never be able to hide a deletion — while the subtraction happens
after it, on the payload alone. The row-trigger rule then runs on the trimmed set, so an entity
whose only change was diverted to a translation row produces no entry rather than an empty one.

The adapter is deliberately three lines. Both it and the listener need the effective locale
resolved the same way, and a second implementation of that is how the two would drift.

### Two behaviours worth knowing before you change them

* **Fail-closed gating.** A translation object whose source entity is not loaded (foreign-key
  translations, source absent from the identity map) is skipped, because `CaptureGateInterface`
  cannot be evaluated without the object and loading it would be a query. Recording past a gate
  that might have vetoed the entry is the worse failure. Logged once per class.
* **No exception on a missing identifier.** `AuditableEntityNotSupported::noIdentifier()` is not
  thrown here: an audit bridge must not break a legitimate flush. Composite identifiers still
  throw, but from `AuditableResolver::isAuditable()`, as everywhere else — and on **every** call,
  not once per class: `isAuditable()` asserts the identifier *before* writing its memo, so the
  memo is never reached for a class that fails. That is the intended behaviour and must stay that
  way. An `#[Auditable]` class with a composite key is a programming error, and one that stopped
  throwing after its first flush would let every subsequent write through unaudited.
