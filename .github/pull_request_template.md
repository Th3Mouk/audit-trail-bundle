## What this changes

<!-- One paragraph. What behaviour is different after this is merged? -->

## Why

<!-- The problem, not the patch. Link the issue if there is one. -->

## Checklist

- [ ] `composer ci` passes locally (`cs:check`, `stan`, then all three suites).
- [ ] Tests live at the right level: unit for capture semantics and metadata, integration
      for the `onFlush` contract against a real `EntityManager`, bridge for the optional
      integrations.
- [ ] No database query was added to the flush path — no lazy association initialised, no
      repository call, no `EntityManager::flush()` from a listener.
- [ ] Nothing here assumes anything about a host application: no User class, no actor
      taxonomy, no permission names, no entity names.
- [ ] Any new optional dependency is guarded (`class_exists`/`interface_exists` or
      conditional DI) and the bundle still boots without it.
- [ ] New configuration keys are documented and have a default that is safe to inherit.
- [ ] `CHANGELOG.md` has an entry under `Unreleased`.

## Compatibility

- [ ] No breaking change.
- [ ] Breaking change — described below, with the upgrade path.

<!--
  Public API here means: the interfaces under src/, the `audit_trail` configuration schema,
  the service tags, the public aliases, and the `audit_logs` table shape. Changing any of
  them is a major version.
-->
