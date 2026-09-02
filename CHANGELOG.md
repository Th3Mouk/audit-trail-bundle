# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

For this package, the public API is: the interfaces under `src/`, the `audit_trail`
configuration schema, the service tags, the public service aliases, and the shape of the
`audit_logs` table. A breaking change to any of them is a major version.

## [Unreleased]

### Added

#### Capture

- Attribute-driven auditing of Doctrine entities. `#[Auditable]` on a class opts it in and is
  inherited; a child may opt back out with `#[Auditable(enabled: false)]`.
- Entries are discriminated by a short `entity_type`, never by a class name: `#[Auditable(type:
  'membership')]` declares one, and leaving it out derives kebab-case from the short class name. The
  class name is stored beside the type in a nullable `entity_class` column as information — nothing
  filters, joins or paginates on it — so a rename never orphans a history, and no query string or
  payload carries a namespace. References inside a payload are `{"type": …, "id": …, "label": …}`.
- A type claimed by two audited entities fails `cache:warmup` with `DuplicateAuditType` rather than
  silently merging two histories. Unlike `enabled`, a declared type is not inherited.
- A field policy inside an embeddable is resolved through Doctrine's own mapping, so
  `#[ORM\Embedded(class: …)]` on a property with no PHP type — or an interface or union type — keeps
  its `#[AuditMasked]` and `#[NotAuditable]` declarations, at any nesting depth. A dotted change-set
  key that still cannot be resolved is now **masked rather than recorded**: an unreadable mapping
  must not become a secret in clear.
- The Gedmo translation listener applies the same entity-manager identity check as capture, so a
  translation flushed through another manager is not enlisted in the audited manager's unit of work.
- `AuditLogRepository` pages by `AuditCursor` — both halves of the feed's sort key — and orders by
  `(occurredAt DESC, id DESC)`, the order the HTTP feed serves, the documentation states and every
  index ends with. Paging by identifier alone was exact only until the first backfill.
- `prepend()` resolves `entity_manager` the way Symfony merges configuration, so an environment file
  overriding it installs the mapping on the same manager `load()` binds the services to — including
  when the override is an explicit `null`, which is how an environment returns to Doctrine's default
  manager.
- The type warmer also refuses an empty type or one longer than the 64-character column, so neither
  reaches the database as a failed insert.
- The API Platform bridge requires `api-platform/core` >= 4.3.9 and says so at container build time
  (`UnsupportedApiPlatformVersion`). Below it, a date cursor was cast to a string while building
  `hydra:next`, so every paginated response failed; the floor is enforced only when the feed is
  enabled, so applications that never expose it are unaffected.
- Capture hooks Doctrine's `onFlush` and nothing else. Entries are read from
  `UnitOfWork::getEntityChangeSet()`, and no database query is issued while the flush is in
  flight.
- The row-trigger rule: `#[NotAuditable]` properties are stripped from the change set, and an
  update produces an entry only if a tracked or masked property remains. A flush that touched
  nothing but ignored properties writes no row; a flush that touched nothing but masked
  properties writes exactly one.
- `#[AuditMasked]` records that a property changed without ever reading its value — the mask
  sentinel is emitted from key presence alone. The mask is configurable globally and
  overridable per property.
- Change shapes per action: `{field: {before, after}}` for updates, full state for creates,
  and full state as it stood at deletion for deletes, so a removed row stays legible.
- Owning `ManyToOne`/`OneToOne` associations are captured as `{class, id, label}` with the
  label snapshotted at capture time, so an entry survives the rename or deletion of what it
  points at. The label is only read from an already-initialised object; otherwise it is
  `null`.
- Value mapping for scalars, `\DateTimeInterface` (ISO-8601), backed and pure enums,
  `\Stringable`, and entity references. Anything else is excluded from the entry and reported
  once at warning level through an optional PSR-3 logger.
- Configurable listener priority, because capture has to run *before* listeners that rewrite
  change sets (Gedmo Translatable) and *after* those that stamp fields (Timestampable,
  Blameable).

#### Aggregates and labels

- `#[AuditScope]` denormalises the aggregate root onto each entry through a dotted getter
  path, so a whole tree's history can be read with one indexed query.
- `AuditScopeProviderInterface` as the escape hatch for roots a getter path cannot express —
  conditional, computed, or polymorphic. It takes precedence over the attribute.
- `#[AuditLabel]` designates the human-readable title of an entity, on a property or a
  method.

#### Storage

- `AuditLog`, a read-only Doctrine entity keyed by a UUID v7 — time-ordered, so the primary
  key doubles as the feed's sort key and its keyset cursor while still inserting at the right
  edge of the index. Four composite indexes cover the entity, aggregate, actor and
  chronological reads. The table name is configurable.
- The Doctrine storage writes each entry inside the transaction that produced it, by
  persisting the row and calling `UnitOfWork::computeChangeSet()` for it. A rolled-back change
  takes its audit entry with it.

#### Extension points

- `ActorResolverInterface` (tag `audit_trail.actor_resolver`): a stateless priority chain,
  where returning `null` defers to the next resolver. The actor model is deliberately
  free-form — `id`, `type` and `label` are all optional strings, and there is no built-in
  taxonomy of principals to conform to.
- `CaptureGateInterface` (tag `audit_trail.capture_gate`): every registered gate must agree
  before an entry is recorded. This is the seam for silencing an import or dropping
  cascade-deleted children.
- `ValueSerializerInterface` (tag `audit_trail.value_serializer`): a priority chain for
  teaching the trail about a value object, a money type, or a redaction rule.
- `ActionResolverInterface` (tag `audit_trail.action_resolver`): a priority chain where the
  first non-null answer reclassifies a scheduled UPDATE — a logical delete recorded as a
  `delete`, carrying the entity's state. The capture gates are asked about the resolved action.
- `FieldExclusionInterface` (tag `audit_trail.field_exclusion`): every contributor is consulted
  and the answers merged, to drop fields whose change another `onFlush` listener diverts away
  from the entity's own columns. Updates only, and it can only ever remove a field from an
  entry.
- `AuditStorageInterface`, `LabelResolverInterface` and `ScopeResolverInterface`, all with
  replaceable, decoratable defaults.
- `AuditLoggerInterface` for facts the ORM cannot see: bulk DQL, raw SQL, or a side effect in
  another system.
- Public aliases for every interface above, so applications can autowire and decorate them.

#### Bridges

- API Platform: an opt-in read-only audit feed with a configurable route prefix, pagination
  limits, and an application-supplied security expression. Auto-detected, and no security
  attribute is emitted unless you provide one.
- Gedmo: Translatable-aware change capture and SoftDeleteable-aware delete mapping,
  auto-detected, each half switchable on its own through `bridges.gedmo.translatable` and
  `bridges.gedmo.soft_deleteable`. Both reach capture through the generic
  `audit_trail.action_resolver` and `audit_trail.field_exclusion` tags, so no Gedmo symbol
  appears in the core. The translation listener has its own priority,
  `bridges.gedmo.listener_priority`, defaulting to `1` — strictly above the `0` Gedmo's own
  listeners land on, which is what it has to be to read a change set Gedmo has not yet
  reverted.
- Symfony Security: a default actor resolver reading the authenticated principal from the
  token.
- All three bridges are guarded. The bundle boots with none of their packages installed.

#### Configuration

- `audit_trail.enabled` as a global kill switch: the listener and the logger both become
  no-ops, so capture can be disabled without unwiring anything.
- `table_name`, `mask`, `listener_priority`, `capture.state_on_create`,
  `capture.state_on_delete`, `capture.suppress_cascade_children`, and the per-bridge
  `bridges.*` sections. Every option is read by something; `config:dump-reference audit_trail`
  is the whole surface.

### Fixed

- `SecurityTokenActorResolver` is now actually registered when `symfony/security-bundle` is
  installed. `AuditTrailExtension::load()` decided this with
  `ContainerBuilder::hasExtension('security')`, which is unconditionally `false` from inside an
  extension's own `load()` — Symfony compiles every extension's configuration against an
  isolated, throwaway container (`MergeExtensionConfigurationPass`), so the check failed in every
  application, regardless of bundle order. The decision now happens in
  `RegisterSecurityActorResolverPass`, a compiler pass that runs against the real, fully-merged
  container, the same way `RegisterAuditFeedPass` already decides for the API Platform bridge.
  The single-argument `$container->register(SecurityTokenActorResolver::class)` this replaces
  also left the definition's class attribute unset, which would have failed the build outright
  the moment the guard above stopped hiding it.

### Security

- The trail is soft append-only by default. Making it hard append-only is an infrastructure
  decision; see [SECURITY.md](SECURITY.md) for the `REVOKE`/`GRANT` that does it.

[Unreleased]: https://github.com/th3mouk/audit-trail-bundle/commits/main
