# Configuration

Every option has a default; a working installation needs no configuration file at all.
`php bin/console config:dump-reference audit_trail` prints the same tree with its inline
documentation.

## Full annotated reference

```yaml
audit_trail:
    # Global kill-switch. False makes the Doctrine listener and the manual logger no-ops,
    # while every service stays registered so decoration and tests keep working.
    # Change it: to run an environment without a trail, or to A/B the cost of capture.
    enabled: true

    # Name of the single table holding the trail. The entity mapping is rewritten at runtime,
    # so the change shows up in doctrine:migrations:diff like any other mapping change.
    # Change it: the name is taken, or your conventions differ.
    table_name: 'audit_logs'

    # Sentinel stored instead of the value of a property marked #[AuditMasked].
    # A per-property mask — #[AuditMasked(mask: '[redacted]')] — overrides it.
    # Change it: house style, or you want the mask to be greppable/obviously non-value.
    mask: '********'

    # Priority of the onFlush capture listener. It has to run AFTER listeners that stamp
    # fields (Timestampable, Blameable) so their values are in the change set, and BEFORE
    # listeners that rewrite change sets (Gedmo Translatable).
    # Change it: you have third-party onFlush listeners. See bridges/gedmo.md.
    listener_priority: 0

    capture:
        # Record the full state of a created entity, so the first line of a history reads on
        # its own instead of pointing at a row that may since have changed.
        # Change it: only to shrink the table, and only if you accept unreadable creations.
        state_on_create: true

        # Record the full state of a deleted entity, captured while it is still hydrated.
        # Change it: almost never — this is what makes a deletion legible after the fact.
        state_on_delete: true

        # Skip an auditable child whose owning root is deleted in the same flush, so removing
        # one aggregate does not produce a burst of entries nobody reads.
        # Change it: false when children are meaningful on their own and you want them all.
        suppress_cascade_children: true

    bridges:
        gedmo:
            # null auto-detects gedmo/doctrine-extensions. Set explicitly to force on/off.
            enabled: ~
            # Audit translated content, and trim the fields Gedmo diverts to a translation row
            # out of the entity's own update entry. false removes both — including the trimming,
            # so an update in a non-default locale then claims a column change Gedmo reverts.
            translatable: true
            # Record a logical delete (Gedmo's, or a hand-written setDeletedAt()) as a `delete`
            # carrying the entity's state. false leaves every one of them an ordinary update.
            soft_deleteable: true
            # Priority of the TRANSLATION listener — a different listener from capture, with a
            # different neighbour. It must stay strictly above Gedmo's own listeners, which sit
            # at 0; below them it reads an already-reverted change set and records nothing.
            # Change it: only if your Gedmo listeners are not at priority 0.
            listener_priority: 1

        api_platform:
            # null auto-detects api-platform/core. Set explicitly to force on/off.
            enabled: ~
            # Path the read-only feed is mounted on.
            route_prefix: '/audit_logs'
            # Default page size of the feed.
            items_per_page: 50
            # Ceiling a client may ask for, so the feed cannot be turned into a table export.
            # Must be >= items_per_page (validated at compile time).
            max_items_per_page: 200
            # Access-control expression applied to the feed. null emits NO security attribute
            # at all: the bundle never invents a permission name.
            # Change it: always, unless your firewall already protects the route.
            security: ~
```

## Option-by-option

| Option | Default | Change it when |
| --- | --- | --- |
| `enabled` | `true` | an environment should run without a trail; tests that measure the cost of capture |
| `table_name` | `audit_logs` | the name collides, or house conventions differ |
| `mask` | `********` | you want a different sentinel; per-property masks override it |
| `listener_priority` | `0` | third-party `onFlush` listeners need to run before or after capture |
| `capture.state_on_create` | `true` | you accept unreadable creations in exchange for a smaller table |
| `capture.state_on_delete` | `true` | practically never |
| `capture.suppress_cascade_children` | `true` | cascaded children are meaningful on their own |
| `bridges.gedmo.enabled` | auto | force off with Gedmo installed but unused; force on in a test kernel |
| `bridges.gedmo.translatable` | `true` | you do not want translation entries, and accept losing the field trimming with them ([why](bridges/gedmo.md#the-consequence-of-running-before-gedmo-and-what-is-done-about-it)) |
| `bridges.gedmo.soft_deleteable` | `true` | a logical delete should read as an update of the date column ([why](bridges/gedmo.md#soft-deletes)) |
| `bridges.gedmo.listener_priority` | `1` | your Gedmo listeners are not at priority `0` — keep it strictly above them ([why](bridges/gedmo.md#listener-ordering)) |
| `bridges.api_platform.enabled` | auto | api-platform installed but the trail must not be exposed |
| `bridges.api_platform.route_prefix` | `/audit_logs` | route conventions, or an API version prefix |
| `bridges.api_platform.items_per_page` | `50` | your UI pages differently |
| `bridges.api_platform.max_items_per_page` | `200` | you trust (or distrust) clients more |
| `bridges.api_platform.security` | none | as soon as the feed is reachable — see [bridges/api-platform.md](bridges/api-platform.md#security) |

## Notes that save time

**`enabled: false` is not the same as removing the bundle.** Services stay in the container, the
public aliases still resolve, and `AuditLoggerInterface` still injects — its methods just do
nothing. Instrumentation can stay in the code.

**Runtime pausing is a separate lever.** `EnabledGate::pause()` silences capture for a bulk import
without touching configuration. Pair it with `resume()` in a `finally` — the bundle does not tag the
gate for `kernel.reset`, so nothing resumes it for you. See
[extending.md](extending.md#capture-gate-silencing-an-import).

**Bridge auto-detection is compile-time.** `bridges.gedmo.enabled: ~` resolves to
`class_exists(Gedmo\Translatable\TranslatableListener::class)`; `bridges.api_platform.enabled: ~`
resolves to api-platform being installed *and* registered *and* configured with Doctrine ORM
support.

Forcing `bridges.api_platform.enabled: true` without the package does **not** fail: the compiler
pass re-checks that api-platform is really there and registers nothing when it is not, so the
feed is simply absent. Treat the option as "never expose the trail" (`false`) rather than as a way
to assert the bridge is active.

**`listener_priority` is the one option you may have to tune blind.** Gedmo registers its own
listeners at priority `0`, so at the default the ordering depends on registration order. If
`$em->remove()` on a soft-deleteable entity records **nothing at all**, raise it above them.

**The two `listener_priority` options are different listeners.** `audit_trail.listener_priority`
places *capture*, which has to sit after the stampers (Timestampable, Blameable) and before the
rewriters (Translatable, SoftDeleteable). `audit_trail.bridges.gedmo.listener_priority` places the
*translation* listener, which only cares about being above Gedmo's Translatable. Neither moves the
other, and only the first one has a trade-off to make. See
[bridges/gedmo.md](bridges/gedmo.md#listener-ordering).

Two things `listener_priority` no longer has to fix: a soft delete written by hand
(`setDeletedAt()`, no `remove()`) is recorded as a `delete` at every priority, and translation
auditing works at Gedmo's own default of `0`.
