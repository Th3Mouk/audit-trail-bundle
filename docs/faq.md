# FAQ

## Is it slow?

No, and the reason is structural rather than a benchmark: **nothing the bundle itself does inside
`onFlush` reaches the database**. It reads the change set, the identity map and already-initialised
properties, and nothing else; every resolver ends its walk with `null` rather than doing I/O.

The bundle asserts this by measuring the same cascading deletion twice — once with capture on, once
with `enabled: false` — and requiring the `SELECT` counts to match.

Where it stops being structural is at the code *you* supply. A `#[AuditLabel]` **method**, a
`__toString()` and an `AuditScopeProviderInterface::resolveAuditRoot()` are all called inside the
flush, and the bundle cannot tell whether one of them is about to touch an unloaded association. That
is a contract, not a guarantee: keep them to already-loaded state, and prefer a labelled *property*
to a labelled method, which is why the README's example uses one.

Reflection is memoised per class (and per class+property for field policy), so the attribute walk is
paid once per process, not once per row.

## What does it cost per write?

One extra `INSERT` per audited entity change, in the transaction you were already opening. No extra
read, no extra round trip for the trail itself.

The parts that are not free, in rough order of importance:

| Cost | Notes |
| --- | --- |
| one `INSERT` per entry | the actual cost; a flush touching 50 audited entities writes 50 rows |
| JSON encoding of the payload | proportional to the number of changed fields |
| four indexes to maintain | the price of the four questions the trail answers |
| reflection | memoised; negligible after warm-up |

Flushes that produce nothing are close to free: a change touching only `#[NotAuditable]` fields exits
before serializing anything, and the actor is not even resolved when no entry was collected.

Two levers if the volume is genuinely a problem: `#[NotAuditable]` on high-churn technical columns, and
`EnabledGate::pause()` around bulk work ([extending.md](extending.md#capture-gate-silencing-an-import)).

## How big does the table get, and what about retention?

It grows monotonically, roughly `rows ≈ audited writes`, at a few hundred bytes to a few kilobytes per
row depending on how wide your entities are (create/delete entries carry full state, updates carry only
the diff).

The bundle ships **no** retention policy, because retention is a legal and business decision — six
months for one table, ten years for another, forever for a third — and a library that guessed would be
wrong for everyone.

`idx_audit_occurred` exists so pruning is cheap. A monthly job:

```sql
DELETE FROM audit_logs
 WHERE occurred_at < now() - INTERVAL '18 months'
   AND entity_type NOT IN ('contract', 'payment');
```

If you keep years of history, partition by `occurred_at` (monthly range partitions on PostgreSQL) and
detach instead of delete — `DROP`ping a partition is instant, and a 200-million-row `DELETE` is not.
Nothing in the bundle depends on the table being unpartitioned.

## Does it capture reads?

No. Nothing about a `SELECT` reaches `onFlush`, and read auditing is a different system: orders of
magnitude more events, a different retention policy, usually a different store. See
[architecture.md](architecture.md#deliberately-out-of-scope).

## Can someone tamper with it?

Be precise about what you have. The trail is **soft append-only**:

- the entity is mapped `readOnly: true`, so Doctrine never computes an update for a trail row;
- no code path in the bundle updates or deletes one;
- there is no HTTP write operation — the feed answers `405` to every write method
  ([bridges/api-platform.md](bridges/api-platform.md#endpoints)).

So nothing *accidentally* rewrites history. But anyone holding the application's database credentials
can run `UPDATE audit_logs …`, and the bundle cannot prevent that: it uses the connection you gave it.

**Hard append-only is a database privilege**, and it belongs to whoever owns the schema. On PostgreSQL,
with a separate owner role:

```sql
-- as the table owner, against the role your application connects with
REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM app_user;
GRANT  SELECT, INSERT                ON audit_logs TO   app_user;
```

Then run retention pruning as the owner (a cron job with its own credentials), not as the application.
Do this and an attacker with application credentials can add entries but never erase one — which is the
property that actually matters.

The bundle deliberately ships **no** hash chain or signature. A chain needs a strict order and a
previous-hash read on every insert: that serialises writes, puts a query back inside the flush
(breaking the no-queries invariant), and only helps if you also protect the key and verify the chain.
If you need tamper-*evidence* rather than tamper-*resistance*, ship entries to an append-only sink from
a [storage decorator](extending.md#storage) and let that system own the guarantee.

## Why opt-in rather than audit-everything?

Three reasons, in order:

1. **Write volume.** Audit-everything means every session touch, every cache-warming counter, every
   `last_seen_at` bump becomes a row. The trail then grows faster than the data it describes and gets
   turned off within a quarter.
2. **Signal.** A trail nobody can read is not evidence. When only the entities that matter are in
   there, "what happened to this contract" returns twenty rows instead of twenty thousand.
3. **Reviewability.** `grep -rn '#\[Auditable\]' src/` is the complete, greppable, diffable answer to
   "what do we audit". With a wildcard, that answer lives in a config file that drifts, and nobody
   notices the day a new entity started (or stopped) being covered.

The corollary is that within an audited entity the polarity flips: fields are **tracked by default**,
so a column added next month is audited the day it appears. See
[attributes.md](attributes.md#field-policy).

## How do I audit a JSON column safely?

Arrays are not in the shipped value mapping. A JSON column's change is therefore **excluded** from the
payload — silently as far as the entry goes, and once per type at warning level in your logs. That is
deliberate: a wrong `after` in an audit trail is worse than a missing one, and there is no single
correct rendering of an arbitrary array.

You have three honest options.

**1. Do not audit it.** If the column is a bag of UI preferences, `#[NotAuditable]` says so explicitly
and stops the log warning.

**2. Record a fingerprint** — enough to prove *that* it changed, without storing content you may not
want in a second table (and without unbounded rows):

```php
#[AutoconfigureTag('audit_trail.value_serializer', ['priority' => 300])]
final readonly class SettingsPayloadSerializer implements ValueSerializerInterface
{
    public function supports(mixed $value): bool
    {
        return $value instanceof SettingsPayload;      // a value object, not a bare array
    }

    public function serialize(mixed $value): mixed
    {
        return ['keys' => $value->keys(), 'digest' => $value->digest()];
    }
}
```

**3. Record the payload, deliberately.** A serializer can accept bare arrays — but then own the
consequences:

```php
public function supports(mixed $value): bool
{
    return \is_array($value);
}

public function serialize(mixed $value): mixed
{
    return $value;
}
```

Before shipping that, check three things: the array is **bounded** (a 2 MB blob duplicated on every
write will dominate the table), it holds **no secrets** (an audit table is usually readable by more
people than the source column), and it is **JSON-encodable** (no objects, no resources, no recursion).

The strongly typed route is better on all three counts: wrap the column in a value object and register
a serializer for *that*. `supports()` then answers a real question instead of `is_array()`, and the
mask/ignore attributes stay meaningful.

See [extending.md](extending.md#value-serializer-a-value-object) and
[attributes.md](attributes.md#what-the-values-look-like).

## Why is the `entity_id` of some `create` entries empty?

Because `onFlush` runs before the `INSERT`, so an entity whose key the database generates has no
identifier yet — and the entry is built at that moment precisely so it shares the transaction. Assign
identifiers in the constructor (UUID v7) and it goes away. Full explanation:
[architecture.md](architecture.md#the-atomicity-guarantee).

## Can I turn it off in one environment?

`audit_trail.enabled: false`. The listener and the manual logger become no-ops while every service stays
registered, so injected `AuditLoggerInterface` calls and decorations keep working. See
[configuration.md](configuration.md).

## Why is my soft delete recorded as an update, or not at all?

With the [Gedmo bridge](bridges/gedmo.md#soft-deletes) on, a logical delete — Gedmo's or a
hand-written `setDeletedAt()` — is recorded as a `delete` carrying the entity's full state. If you
are seeing something else, one of three things:

- **`bridges.gedmo.soft_deleteable: false`**, which switches the reclassification off on purpose.
- **`$em->remove()` recorded nothing at all.** That is [listener
  ordering](bridges/gedmo.md#listener-ordering): Gedmo cancelled the removal before capture saw it,
  and the entity ends up in neither `entityDeletions` nor `entityUpdates`, so there is no change set
  left to reclassify. Raise `audit_trail.listener_priority` above Gedmo's listeners. A hand-made soft
  delete is unaffected by ordering.
- **The date column moved from one date to another**, which is an ordinary update and recorded as
  one. Only `null ↔ non-null` transitions are decisions.

A **restore** — the date back to `null` — is deliberately an `update` with
`deletedAt: <date> → null`, not a fourth action. See
[bridges/gedmo.md](bridges/gedmo.md#soft-deletes).
