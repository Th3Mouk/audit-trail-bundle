# Contributing

Thanks for being here. This document covers how to run the gates, where a test belongs, and
the two invariants that are not negotiable.

If this is your first change to the bundle, read [AGENTS.md](AGENTS.md) first — it orients you in
the codebase, gives the rules that decide most design questions, and lists the mistakes already made
here. It is written for agents and humans alike.

## Getting set up

```bash
git clone https://github.com/th3mouk/audit-trail-bundle
cd audit-trail-bundle
composer install
```

PHP 8.4 or newer, and that is the whole list. There is no lock file — a library does not
commit one — so `composer install` resolves fresh. There is no database to provision either:
the test suite defaults to an in-memory SQLite database.

## Running the gates

Every gate is a Composer script, and CI runs exactly these:

| Command                      | What it does                                                            |
| ---------------------------- | ----------------------------------------------------------------------- |
| `composer cs:check`          | php-cs-fixer in dry-run mode, with a diff.                              |
| `composer cs:fix`            | The same, applied.                                                      |
| `composer stan`              | PHPStan at level 8 over `src/` and `tests/`. No baseline — keep it that way. |
| `composer rector`            | Rector in dry-run mode.                                                 |
| `composer rector:fix`        | The same, applied.                                                      |
| `composer test`              | All three suites, in order.                                             |
| `composer test:unit`         | The `unit` suite alone.                                                  |
| `composer test:integration`  | The `integration` suite alone.                                          |
| `composer test:bridge`       | The `bridge` suite alone.                                               |
| `composer ci`                | `cs:check`, `stan`, then `test`. Run this before opening a pull request. |

`composer ci` is the whole gate. If it is green locally it will be green on CI, with two
exceptions CI covers and your machine does not: the dependency matrix, and Postgres.

### Running against PostgreSQL

`phpunit.xml.dist` sets `DATABASE_URL` without `force`, so an exported value wins. Point it
at a real server to run the same suites against the schema they will meet in production:

```bash
docker run --rm -d -p 5432:5432 \
  -e POSTGRES_USER=audit -e POSTGRES_PASSWORD=audit -e POSTGRES_DB=audit_trail \
  postgres:17-alpine

DATABASE_URL="postgresql://audit:audit@127.0.0.1:5432/audit_trail?serverVersion=17&charset=utf8" \
  composer test:integration
```

SQLite is enough to prove the capture semantics. It is not enough to prove the schema:
`TIMESTAMP WITH TIME ZONE`, `JSON` columns and the four composite indexes only mean
something on a real server. Anything that touches `AuditLog`'s mapping should be checked on
Postgres before review.

### Testing the dependency range

The bundle supports Symfony `^7.4 || ^8.0`, and CI proves it across PHP 8.4/8.5 and both
lowest and highest dependency resolutions. To reproduce one cell locally:

```bash
composer global config --no-plugins allow-plugins.symfony/flex true
composer global require symfony/flex

SYMFONY_REQUIRE=7.4.* composer update --prefer-stable --prefer-lowest
composer test
```

`--prefer-lowest` is where surprises live. It is the run that catches a method you used that
only exists in a newer minor than your constraint admits.

## Where a test belongs

The pyramid is enforced by the suite layout, not by convention alone.

**`tests/Unit`** — no container, no database, no `EntityManager`. This is where the
interesting work happens, because almost everything interesting here is a pure decision:

- the row-trigger rule (only-ignored changed ⇒ no entry; only-masked changed ⇒ one entry),
- masking (the mask is emitted from `array_key_exists`; the value is never read),
- the shape of `changes` per action — `{field: {before, after}}` for updates, flat state for
  creates and deletes,
- value mapping, including the "unsupported ⇒ excluded and warned once" path,
- attribute resolution, inheritance, and `#[AuditScope]` path walking,
- the model classes and their named constructors.

If a behaviour can be tested here, it must be. Most of this bundle's rules can.

**`tests/Integration`** — a booted kernel and a real `EntityManager`. This suite exists for
exactly one thing: the `onFlush` contract. That the audit row lands in the same transaction
as the change it describes; that a rollback takes it with it; that a delete is captured with
the state it had while it was still hydrated; that no query is issued while the flush is in
flight.

**`tests/Bridge`** — the optional integrations. Every test here must skip cleanly when its
dependency is absent (`class_exists`/`interface_exists` in the test itself), because CI runs
this suite in installations that do not have them.

A few things we would rather not merge: a test that asserts a getter returns what the
constructor was given, a test that restates the implementation as an expectation, and two
tests at different levels covering the same branch. If a test would not fail for a plausible
mistake, it is documentation with a runtime cost.

## The invariants

### No queries during `onFlush`

This is the one that will get a pull request sent back.

The listener runs inside Doctrine's flush. Issuing a query there — or touching anything that
issues one — corrupts the unit of work mid-computation, and in the best case degrades every
write in the application into an N+1. So, while capturing:

- Never initialise a lazy association. Reading an uninitialised proxy is a query.
- Never call a repository, a DQL query, or `Connection` directly.
- Never call `EntityManager::flush()`. Storage persists the new `AuditLog` and calls
  `UnitOfWork::computeChangeSet()` for it; Doctrine writes it as part of the flush already in
  progress. `postFlush` is not an alternative — it is outside the transaction.
- Read diffs with `UnitOfWork::getEntityChangeSet()`. Take identifiers from
  `UnitOfWork::getEntityIdentifier()`, the identity map, or the change set itself.
- Guard typed non-nullable properties with `isset()` rather than `=== null`: reading an
  uninitialised typed property throws.
- When the answer would require I/O, return `null`. A label snapshot that is missing is a
  cosmetic loss. A query in the flush path is a production incident.

`AuditScopeProviderInterface` and the label/scope resolvers all run under this rule.

### Nothing about the host application

The bundle must not learn what a "user" is. No `User` class, no built-in actor taxonomy
(`Actor::$type` is a free-form string and stays that way), no permission names, no entity
names. When you need behaviour that depends on such knowledge, add an extension point and
ship a replaceable default:

- an interface in `src/`,
- a service tag (`audit_trail.actor_resolver`, `audit_trail.capture_gate`,
  `audit_trail.value_serializer`) with `priority` support, or a decoratable default,
- a public alias so applications can autowire and decorate it.

Optional dependencies — api-platform/core, gedmo/doctrine-extensions,
symfony/security-bundle — are guarded with `class_exists`/`interface_exists` or conditional
DI registration. The bundle boots with none of them installed, and the `bridge` suite proves
it.

## Style

`composer cs:fix && composer rector:fix` settles the mechanical part. The rest:

- `declare(strict_types=1);` everywhere; `final` by default; `readonly` for injected
  services; constructor property promotion.
- `\DateTimeImmutable`, never `\DateTime`.
- No explanatory comments inside method bodies. If a block needs a comment to be understood,
  it needs a name. Docblocks are for array shapes, generics, and public-API prose.
- Exceptions carry no `Exception` suffix, are named as the negative sentence they represent
  (`AuditableEntityNotSupported`, `ConflictingFieldPolicy`), are built through named
  constructors, and implement `Th3Mouk\AuditTrail\Exception\AuditTrailException`.

## Pull requests

Small, one subject, and green. Add a `CHANGELOG.md` entry under `Unreleased`. If you change
an interface, the configuration schema, a service tag, a public alias, or the `audit_logs`
table shape, say so in the description — that is a major version, and it needs an upgrade
path.
