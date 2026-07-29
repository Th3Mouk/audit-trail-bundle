# Architecture

For contributors, and for anyone deciding whether to trust this thing.

## The capture pipeline, end to end

One Doctrine hook: **`onFlush`**. That is the whole entry point.

```
Doctrine flush
  └─ AuditLogListener::onFlush()                      priority = audit_trail.listener_priority
       │
       ├─ enabled? ────────────────────── no ──▶ return
       ├─ FlushState::enterFlush()               (so storage knows it is mid-flush)
       │
       ├─ for each scheduled INSERT / UPDATE / DELETE:
       │    ├─ AuditableResolver::isAuditable()          #[Auditable] walked up the hierarchy, memoised
       │    │                                            composite identifier ⇒ throw, aborting the flush
       │    ├─ UPDATE only : ChainActionResolver::resolveAction()   the whole change set; first non-null wins,
       │    │                                            no opinion ⇒ Update  (a logical delete ⇒ Delete)
       │    ├─ ChainCaptureGate::shouldCapture()          every gate must agree (EnabledGate, CascadeSuppressionGate, yours),
       │    │                                            asked about the RESOLVED action
       │    ├─ payload
       │    │    ├─ UPDATE : UnitOfWork::getEntityChangeSet()
       │    │    │             ├─ minus ChainFieldExclusion::excludedFields()   fields diverted elsewhere (Gedmo Translatable)
       │    │    │             └─▶ ChangeSetSerializer::serializeChangeSet()    row-trigger rule; null ⇒ no entry
       │    │    └─ CREATE / DELETE : full state         ──▶ ChangeSetSerializer::serializeState()
       │    ├─ per field  : FieldPolicyResolver           Tracked | Masked | Ignored
       │    ├─ per value  : ChainValueSerializer          scalars → dates → enums → entity refs → stringables
       │    ├─ EntityIdResolver::resolve()                identity map / UnitOfWork, never a query
       │    ├─ LabelResolverInterface::resolve()          #[AuditLabel] → __toString() → null
       │    └─ ScopeResolverInterface::resolve()          AuditScopeProviderInterface → #[AuditScope] walk
       │
       ├─ nothing collected? ──────────── yes ─▶ return  (actor is not even resolved)
       ├─ ActorResolverInterface::resolve()               once per flush, for every entry
       │
       └─ for each entry: AuditStorageInterface::store(AuditEntry)
            └─ DoctrineAuditStorage
                 ├─ AuditLog::fromEntry()                UUID v7 assigned here
                 ├─ $em->persist($log)
                 └─ mid-flush? $uow->computeChangeSet(metadata, $log)     ← joins THIS transaction
```

Reads use `getEntityChangeSet()`. `computeChangeSet()` is called for exactly one thing: the freshly
created `AuditLog` rows, which Doctrine has already made up its mind about and would otherwise ignore.

**Why a scheduled UPDATE gets two extra questions.** Capture has to run before the `onFlush`
listeners that rewrite change sets, which means it reads a change set that is about to be edited
behind it. Two things can be wrong about it, and each has its own seam so that the core stays
ignorant of whoever is doing the rewriting:

| Seam | Tag | Chain | Answers |
| --- | --- | --- | --- |
| `Capture\ActionResolverInterface` | `audit_trail.action_resolver` | first non-null wins | "this UPDATE is really a deletion" |
| `Capture\FieldExclusionInterface` | `audit_trail.field_exclusion` | answers merged | "this field's change is not going to the entity's own column" |

Both are empty in a container with no bridge and no application implementation, in which case the
pipeline behaves exactly as it always did. Order between them is not interchangeable: the action is
resolved from the **whole** change set, so an exclusion can never hide a deletion, and the
subtraction then applies to the payload alone. The [Gedmo bridge](bridges/gedmo.md) is the shipped
implementation of both; [extending.md](extending.md) is how to add your own.

`AuditEntry` is a plain immutable model, deliberately independent of storage — which is what makes the
whole capture pipeline unit-testable without a database.

**Request context.** `RequestContext` holds a correlation id and a client IP, filled from the main
HTTP request by a `kernel.request` listener at priority 1024. The correlation id is read from the
`X-Request-Id` header; sub-requests are ignored, and a value longer than 64 characters is dropped
rather than truncated into something that correlates with nothing.

It is read by the [manual logger](manual-logging.md) only. `AuditLogListener` does not depend on it,
so every automatically captured row leaves `request_id` and `ip` null; a
[storage decorator](extending.md#storage) is the seam if you want them there too.

The holder implements `ResetInterface`, but the bundle registers it — like `FlushState` and
`EnabledGate` — without the `kernel.reset` tag, so nothing clears it between messages in a
long-running worker. Set it yourself at the start of a message, or tag the service in your
application.

## The atomicity guarantee

The audit row is written **inside the transaction that writes the change**. A rolled-back change takes
its entry with it; a committed change cannot commit without one.

This is not a nice-to-have. An audit trail that can disagree with the data is worse than no trail,
because it is trusted. The two possible failures are both silent — a change that commits without its
entry, and an entry describing a change that never happened — and the only structural defence is
sharing the transaction. Hence: `onFlush` + `computeChangeSet`, never `postFlush`, never a second
`flush()`.

The bundle's `AtomicityTest` asserts both directions, and asserts *inside* the transaction that both
rows really were written first — otherwise "nothing is there afterwards" would also pass if capture had
simply never run.

**The one price paid for it.** `onFlush` runs before the `INSERT`, so an entity whose key the database
assigns has no identifier yet. Its `create` entry therefore carries an empty `entity_id` — right class,
right label, right root, full initial state, but not findable by identifier. Updates and deletions are
unaffected; entities keyed by the application (UUID in the constructor) are unaffected. The tension is
real and the trade is made explicitly in favour of atomicity. Assign your own identifiers and it
disappears — which is also what makes [Gedmo translations](bridges/gedmo.md) fully covered.

## The two hard invariants

Break either and you get bugs that surface in production, not in tests.

### 1. No database queries during `onFlush`

Not a `SELECT`, not a lazy-load, not an association initialisation. Everything the pipeline needs is
read from what is already in memory: the change set, the identity map,
`UnitOfWork::getEntityIdentifier()`, initialised properties.

Every resolver therefore returns `null` rather than doing I/O — a missing label, a missing root, an
`AuditRef` without a label. Guard typed non-nullable properties with `isInitialized()` rather than
comparing to `null`, which would trigger initialisation.

The invariant is asserted the only way that cannot be argued with: the same cascading deletion is
measured twice, once with capture on and once with the bundle switched off, and the `SELECT` counts have
to match. That way no one has to arbitrate which statements "belong" to the trail.

### 2. Never call `flush()` from a listener

Flushing from inside a flush corrupts the unit of work. Flushing from outside one decides, on the
caller's behalf, when their transaction ends. `DoctrineAuditStorage` never flushes in either case: it
persists, and — when `FlushState` says it is mid-flush — computes the change set so the row is written
with the rest.

`FlushState` exists because Doctrine offers no public "am I mid-flush?" and guessing from internals
breaks on a minor upgrade. The listener simply says so, with a depth counter, because flushes nest.

## Deliberately out of scope

| Not supported | Why |
| --- | --- |
| **collection / many-to-many deltas** | `getScheduledCollectionUpdates()` is deliberately never read. A pivot-row change is a fact with an identifier — audit the owning child entity and you get an actor, a root and a diff. A collection delta is a shape no one can filter, index or explain later. |
| **read auditing** | Nothing about a `SELECT` reaches `onFlush`, and "who looked at this" is a different system with a different write volume (orders of magnitude higher), a different retention policy, and usually a different store. Bolting it on here would compromise the write path for everyone. |
| **tamper-evidence** (hash chains, signatures) | It sounds free and is not: a chain needs a strict order and a previous-hash read on every insert, which serialises writes and puts a query back inside the flush — breaking invariant 1 for a property that only helps if you also protect the key and verify the chain. If you need it, do it downstream: ship entries to an append-only store. See [faq.md](faq.md#can-someone-tamper-with-it). |
| **hard append-only enforcement** | The entity is mapped `readOnly: true` and nothing in the bundle updates or deletes a row, but the bundle cannot revoke its own `UPDATE`/`DELETE` grants — the connection it is given is the one the application configured. Enforcement is a database privilege, and it belongs to whoever owns the schema. The [REVOKE snippet](faq.md#can-someone-tamper-with-it) is there. |
| **composite identifiers** | One `entity_id` column. Supporting tuples means either a JSON key nobody can index usefully or a second table; both cost more than the case is worth. Marking such a class `#[Auditable]` throws at capture time rather than recording half a key. |
| **arrays as values** | A wrong `after` in an audit trail is worse than a missing one, and there is no single correct rendering of an arbitrary array. Excluded, logged once per type, and fixable with one [value serializer](extending.md#value-serializer-a-value-object). |
| **a `User` class, an actor taxonomy, permission names** | This is a generic package. Every one of those is application vocabulary, and each is an extension point instead: [actor.md](actor.md), [bridges/api-platform.md](bridges/api-platform.md#security). |

## Optional dependencies

The bundle must boot with `api-platform/core`, `gedmo/doctrine-extensions` and
`symfony/security-bundle` all absent. That is enforced structurally, not by convention:
`StandaloneBundleTest` boots a kernel containing only FrameworkBundle, DoctrineBundle and this bundle,
and asserts that none of those services are even in the container. A hidden reference stops the
container from compiling and the whole file fails at once — louder than a README promising
portability.

Concretely, each optional integration is wired by a different mechanism, and each decides for itself
whether it applies:

| Integration | Wired by | Condition |
| --- | --- | --- |
| Gedmo | `bridge_gedmo.php`, loaded from `AuditTrailExtension` | `bridges.gedmo.enabled`, defaulting to `class_exists(TranslatableListener::class)`; each half then kept or removed by `bridges.gedmo.translatable` / `.soft_deleteable` |
| API Platform | `RegisterAuditFeedPass`, a compiler pass installed from `AuditTrailBundle::build()` | api-platform installed *and* registered *and* with Doctrine ORM support, plus `bridges.api_platform.enabled` not `false` |
| security actor resolver | registered inline by `AuditTrailExtension` | `TokenStorageInterface` exists *and* a `security` extension is present |

There is no service file for the API Platform bridge: everything it needs is read from container
parameters by the pass, which also lets the pass run before API Platform's own `FilterPass` — a
constraint bundle registration order cannot express. `AuditLog` carries no API Platform attribute
either; the class only becomes a resource while the pass's decorators are registered.

## Reading the code

| Directory | Role |
| --- | --- |
| `src/Attribute/` | the five declarations — [attributes.md](attributes.md) |
| `src/Metadata/` | `AuditableResolver`, `FieldPolicyResolver`, `FieldPolicy` — memoised reflection |
| `src/Capture/` | change-set serialization, label/scope/id resolution, gates, value serializers, action resolvers, field exclusions |
| `src/Model/` | `AuditEntry`, `Actor`, `AuditRef`, `AuditScopeRef` — immutable, storage-agnostic |
| `src/Storage/` | `AuditStorageInterface`, the Doctrine implementation, `FlushState` |
| `src/EventListener/` | `AuditLogListener` (capture), `TableNameListener` (table rename) |
| `src/Bridge/` | Gedmo and API Platform, each with a maintainer `README.md` next to the code |
| `src/Exception/` | one named constructor per real failure, all implementing `AuditTrailException` |
