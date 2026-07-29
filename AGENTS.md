# Working in this repository

For humans and for agents. [CONTRIBUTING.md](CONTRIBUTING.md) tells you how to run the gates and
where a test belongs; [docs/architecture.md](docs/architecture.md) explains how capture works.
This file is the part neither covers: how to decide, where to put things, and which mistakes have
already been made here so you do not have to repeat them.

## What this is

A Symfony bundle that turns a Doctrine flush into audit entries — who changed what, when, and from
what to what — written inside the very transaction that produced the change. Its distinguishing
promise is not that it records changes; plenty of libraries do. It is that **an entry stays legible
after the rows it describes are gone**, because every reference carries a snapshot of the label it
pointed at, and that **a secret can be recorded as changed without its value ever being stored**.

Nothing on the surface is a class name, either — entries are keyed by a short `entity_type`, so a
refactor cannot orphan a history nor break a saved query. A class name appears only as information,
in a nullable column nothing reads.

Everything in the design serves one of those promises, or the invariant that makes them
trustworthy: capture is atomic with the change.

## Before you change anything

Read, in this order — it takes a few minutes and prevents most wasted work:

1. `src/Model/AuditEntry.php` — the unit of everything. Capture produces these; storage persists
   them. If you understand this class you understand the data flow.
2. `src/EventListener/AuditLogListener.php` — the whole capture flow is in one file, top to bottom.
3. `src/Capture/ChangeSetSerializer.php` — the row-trigger rule and masking live here. This is the
   most consequential file in the bundle.
4. `src/Resources/config/services.php` — the object graph, in one screen.

Then run `composer ci` once so you know what green looks like before you change it.

## Repository map

| Path | What lives there | Rule |
| --- | --- | --- |
| `src/Attribute/` | The five opt-in attributes | Adding a sixth is a design decision, not a detail — see the philosophy below |
| `src/Model/` | Immutable value objects: `AuditEntry`, `Actor`, `AuditRef`, `AuditScopeRef` | No behaviour beyond construction and named constructors. Never reachable from Doctrine |
| `src/Metadata/` | Attribute reading, memoised: `AuditableResolver`, `AuditTypeResolver`, `FieldPolicyResolver`, `FieldPolicy`, plus the `AuditTypeWarmer` build guard | Reflection belongs here and nowhere else |
| `src/Capture/` | The pipeline and its seams | Everything here runs inside `onFlush`. See the invariant |
| `src/Capture/Value/` | The value-serializer chain | Priority order is load-bearing: entity reference must outrank `Stringable` |
| `src/Capture/Gate/` | Capture vetoes | All gates must agree. A gate says *whether*, never *what* |
| `src/Actor/` | Who did it | Knows nothing about users. See the philosophy |
| `src/Storage/` | Where entries go, plus `FlushState` | `DoctrineAuditStorage` is what makes capture atomic. Read its docblock before touching it |
| `src/Entity/`, `src/Repository/` | The one mapped entity and its queries | No API Platform attributes here, ever. The entity must work with api-platform absent |
| `src/EventListener/` | The Doctrine hooks | `AuditLogListener` is core; its priority is configuration, never a constant |
| `src/Bridge/<Name>/` | Optional integrations | May know about its own library. May **not** be known by anything outside it |
| `src/DependencyInjection/` | Config schema and wiring | The only place allowed to `class_exists()` an optional dependency |
| `tests/Unit/` | Pure decisions, no container | Most rules of this bundle are testable here. Prefer it |
| `tests/Integration/` | The `onFlush` contract, real database | Only what a unit test cannot say |
| `tests/Bridge/` | The optional integrations | Must skip cleanly when the dependency is absent |
| `tests/Fixtures/Kernel/` | Three kernels: Doctrine-only, Gedmo, API Platform | The Doctrine-only kernel booting is the proof the bundle has no hidden coupling |

## The philosophy, as decision rules

Four rules. When a change is hard to place, one of them usually decides it.

**1. An extension point beats a rigid framework.** When you need behaviour that depends on
something the bundle cannot know, do not add a configuration flag enumerating the cases. Add an
interface, ship a replaceable default, register a tag, expose a public alias. That is why there are
eight replaceable collaborators plus an entity-side escape hatch (`AuditScopeProviderInterface`), and
why two of them — `ActionResolverInterface` and `FieldExclusionInterface` — exist solely so the Gedmo
bridge can influence capture without the core ever hearing about Gedmo.

**2. The bundle knows nothing about the host application.** No `User` class. No actor taxonomy —
`Actor::$type` is a free-form string chosen by the application, and there is deliberately no
`ActorType` enum, no `user`/`system` distinction, no permission name anywhere in `src/`. "We do not
know who did this" is a legitimate, first-class answer, because console commands and queue workers
genuinely do not know.

If you find yourself about to encode *our* vocabulary into the bundle, stop: most users will either
not need that concept or will need a different one.

**3. Defaults must work unconfigured.** A default that only functions once the application tunes it
is a bug wearing a config option. This has bitten this repository already (see the Gedmo priority
trap below), and the lesson is durable: if a test kernel has to depart from realistic values to make
a feature pass, the feature is broken, not the kernel.

**4. Say what is not covered.** The Gedmo bridge documentation names, precisely, which of Gedmo's
two translation-persistence paths are captured and which are not — because a trail nobody can
calibrate is worse than one with a documented hole. Never round a gap up to "supported". When you
find a limitation, write it down in the page that owns the subject and, when it is worth pinning,
add a test that asserts the *actual* behaviour with a name that makes the gap obvious.

## Recipes

| You want to… | Do this |
| --- | --- |
| Teach the trail a new value type (a money object, a value object) | Implement `ValueSerializerInterface`, tag `audit_trail.value_serializer` with a priority above `StringableValueSerializer` (`-100`). Unit test only |
| Silence noise (an import, a migration) | Implement `CaptureGateInterface`, tag `audit_trail.capture_gate`. Do not add a config flag |
| Reinterpret what an UPDATE means | Implement `ActionResolverInterface`. Return `null` for "no opinion". Note that a resolved `Delete` must carry state-at-delete, like a real deletion |
| Keep some fields out of one entity's diff | Implement `FieldExclusionInterface`. Answers are merged, so an exclusion can only ever remove |
| Change how the actor is found | Implement `ActorResolverInterface`, tag `audit_trail.actor_resolver`. Stateless — the actor is re-read on every call, or a worker will attribute one job's changes to another's |
| Add a configuration option | `Configuration.php` with an `->info()`, a parameter in `AuditTrailExtension`, and a consumer. **An option nothing reads is a defect** — this repo shipped two of them once |
| Change the `audit_logs` shape | Entity + `docs/installation.md`'s SQL block + a `CHANGELOG.md` note. It is a major version: applications own their migration. Render the block from the real mapping rather than editing it by hand |
| Put a class name on the surface — a column, a query parameter, a payload key | Don't. Resolve it to a type through `AuditTypeResolver` first. The class is information; the type is the key |
| Add a bridge for library X | `src/Bridge/X/`, a `bridge_x.php` service file, conditional registration in the extension, a `tests/Fixtures/Kernel/XKernel.php`, and a `tests/Bridge/X/` suite that skips when X is absent. Nothing outside `src/Bridge/X/` may name X |
| Touch the capture pipeline | Re-read the no-queries invariant, then run the integration suite — it is the only thing that can catch you |

## Traps that have already cost time

Every one of these was a real bug in this repository.

**An empty Doctrine change set does not lose a row — it writes a wrong one.**
`BasicEntityPersister::executeInserts()` prepares one statement outside its loop and binds
parameters from `prepareUpdateData()`, which derives solely from the change set. Persist an
`AuditLog` mid-flush without calling `computeChangeSet()` for it and no `bindValue()` runs, so the
statement re-fires with the *previous* row's parameters — surfacing as a primary-key collision that
looks like a UUID collision and is not. This is why `DoctrineAuditStorage` and `FlushState` exist,
and why a `FlushState` mark only lasts for the callback that made it: any listener that records
during a flush must mark it itself.

**Doctrine listener priority: higher runs earlier.** Gedmo's own listeners land on `0`. A listener
that must read the change set *before* Gedmo rewrites it therefore needs a priority **above** `0` —
which is the opposite of what "run afterwards" intuition suggests. The translation bridge shipped at
`-100` once and silently recorded nothing in any real application, while the test suite passed
because its kernel put Gedmo at `-128`. If a test fixture needs unrealistic values, suspect the
default.

**A test kernel's cache directory must be keyed by the checkout.** Two copies of this repository
with the same kernel class and config will otherwise share a compiled container under
`sys_get_temp_dir()`, and Doctrine will scan the *other* copy's mapping directory — a duplicate-class
fatal that looks like an autoload bug. `TestKernel::fingerprint()` hashes `getProjectDir()` for this
reason. Do not remove it.

**A shared audit type merges two histories, and nothing says so.** Types are derived from short class
names when undeclared, so two modules each owning an `Invoice` silently file into one history.
`AuditTypeWarmer` turns that into a failed `cache:warmup`; it asks
`AuditableResolver::isEnabledFor()` and deliberately *not* `isAuditable()`, because the latter also
asserts the entity is supported and would turn a composite-key entity into a boot failure instead of
the flush-time error it is. For the same reason a declared type is not inherited: two children
sharing a parent's name is the collision, not the cure.

**`interface_exists()` on an abstract class always returns false.** `ApiPlatform\Metadata\Operation`
is a class. Detecting an optional dependency with the wrong function is a silently disabled bridge.

**Doctrine change sets can contain `PersistentCollection`, not just before/after pairs.** Collection
deltas are deliberately out of scope, so the listener filters them out before the serializer sees
them. Do not "fix" that by widening the serializer's types.

**A policy that cannot be resolved must fail closed.** `FieldPolicyResolver` walks a dotted
change-set key (`credentials.secret`) to find the property an `#[AuditMasked]` sits on. It used to
read the embeddable's class off the property's PHP *type*, which `#[ORM\Embedded(class: …)]` makes
optional — so an untyped property resolved to nothing, fell back to `Tracked`, and wrote the secret
out in clear. Doctrine's `embeddedClasses` mapping answers first now, and an unresolved *dotted* key
is masked rather than recorded. When a lookup about secrecy fails, the answer is never "record it".

**Every `onFlush` listener needs the entity-manager identity check, not just the first one.**
Doctrine registers a listener on every connection unless the tag names one. `AuditLogListener` had
the guard; the Gedmo translation listener did not, and the integration suite could not see it because
its multi-manager kernel has no Gedmo at all. A new listener is not done until it compares
`$args->getObjectManager()` with the audited manager — before marking the flush.

**Binding the audited manager only reaches services that already exist.**
`bindAuditedEntityManager()` skips what `hasDefinition()` cannot find, so it must run *after* every
bridge file is loaded. Called earlier, a bridge service silently keeps the default manager — a no-op
that looks exactly like a working binding.

**Raw configuration is read in layers, and the last one wins.** `prepend()` runs before the
configuration is processed, so it merges the layers itself. Taking the first `entity_manager` it
finds contradicts `load()`, which reads the merged value: the mapping lands on one manager and the
services on another. Anything read from `getExtensionConfig()` has to fold the same way Symfony's
`Processor` does.

**Rector caches, and a cached run is a false clean.** Rector skips files it has already analysed, so
a rule that starts firing after a dependency update stays invisible locally while CI — which has no
cache — fails. `composer rector` locally proves nothing after `composer update`: pass `--clear-cache`.
The same reasoning applies to php-cs-fixer's `.php-cs-fixer.cache`.

**A stale `vendor/` is not the dependency set CI tests.** This repository commits no lock file, so
every CI job resolves afresh: a local checkout installed weeks ago can sit two minors behind the
"highest" cells and one whole major behind on `doctrine-bundle`. Run `composer update --prefer-stable`
before believing a green local run, and reproduce a `lowest` cell in a scratch copy with
`SYMFONY_REQUIRE=<x.y>.* composer update --prefer-stable --prefer-lowest` — that is where four of the
matrix cells failed while every host gate was green.

**Symfony will not reset your services unless you ask.** `services.php` uses `->autowire()` without
`->autoconfigure()`, so implementing `ResetInterface` achieves nothing on its own — the
`kernel.reset` tag is explicit for `RequestContext`, `FlushState` and `EnabledGate`. Without it, a
worker that pauses capture and then throws silences the trail for every later message.

**When several people (or agents) write in parallel, the seams break, not the files.** The defects
that survived this bundle's initial build were all integration defects: DI arguments naming
constructor parameters that had been renamed, service files referencing namespaces the implementer
had moved, a compiler pass nobody registered, service files nobody owned. Two habits pay for
themselves — cross-check every `->arg('$x')` against the real constructor and every `set(X::class)`
against a class that exists, and never trust "the code is written" over a green `composer ci`.

## What "done" means

`composer ci` green, plus one thing it does not cover:

```bash
# Prove the bundle still works with its optional dependencies genuinely absent.
# CI has a job for this; locally, do it in a scratch copy, never in place.
rsync -a --exclude vendor --exclude .git --exclude composer.lock . /tmp/audit-minimal/
cd /tmp/audit-minimal
composer remove --dev --no-update api-platform/core gedmo/doctrine-extensions \
  symfony/security-bundle symfony/expression-language
composer update --prefer-stable
vendor/bin/phpunit --testsuite=unit && vendor/bin/phpunit --testsuite=integration
```

Run it whenever you touch dependency detection, the extension, or a bridge. Anything that mentions
the `audit_logs` mapping should also be checked against PostgreSQL — SQLite proves the semantics,
not the schema.

## Where things get written down

| Subject | Page |
| --- | --- |
| An attribute, the field policy, the row-trigger rule | `docs/attributes.md` |
| A configuration option | `docs/configuration.md` |
| Aggregate scope, cursors, history panels | `docs/aggregate-history.md` |
| Actor resolution and its philosophy | `docs/actor.md` |
| Recording what the ORM cannot see | `docs/manual-logging.md` |
| A new seam | `docs/extending.md` **and** the README seam table |
| A bridge's coverage and its gaps | `docs/bridges/<name>.md` and `src/Bridge/<Name>/README.md` |
| The pipeline, the invariants, what is out of scope | `docs/architecture.md` |
| Every user-visible change | `CHANGELOG.md`, under `Unreleased` |

The README is the shop window: it may simplify, but it may not claim anything the code does not do.
When you change a public surface, check its code samples still compile against reality.
