# Attributes

Five attributes, and the rule that ties them together.

| Attribute | Target | Effect |
| --- | --- | --- |
| `#[Auditable]` | class | opt the entity in, and name it. Opting in is inherited; a child may set `enabled: false` |
| `#[NotAuditable]` | property | ignore entirely: never stored, and **not** row-triggering |
| `#[AuditMasked]` | property | value never read or stored, but **is** row-triggering |
| `#[AuditLabel]` | property or getter | designates the human title snapshotted onto entries |
| `#[AuditScope]` | class | anchors entries to an aggregate — see [aggregate-history.md](aggregate-history.md) |

## Opting in

```php
use Th3Mouk\AuditTrail\Attribute\Auditable;

#[ORM\Entity]
#[Auditable]
class Invoice { /* ... */ }
```

Auditing is opt-in on purpose: it keeps write volume bounded, and it makes the audited surface a
greppable, reviewable fact — `grep -rn '#\[Auditable\]' src/` is the complete answer to "what do we
audit". See [faq.md](faq.md#why-opt-in-rather-than-audit-everything).

Entities with a **composite identifier** cannot be audited: the trail stores one `entity_id`. When
capture sees such a class, `AuditableEntityNotSupported::compositeIdentifier()` is thrown and the
flush aborts. It is a mapping mistake, reported at capture time rather than silently skipped.

### The name entries are filed under

Entries are discriminated by a short **type**, never by a class name:

```php
#[Auditable(type: 'invoice')]
class Invoice { /* ... */ }
```

A class name is the one part of an entry that does not survive: rename or move the class and every
historical row points at something that no longer exists — in a table built to stay readable after
the data itself is gone. It also leaks your namespace layout into every payload and every URL. So the
type is the key, and the class is kept beside it as information: `entity_class` is nullable, nothing
filters on it, and it is null for entries about things that have no PHP class at all.

Leave `type` out and it is derived from the short class name in kebab-case — `OrganizationMembership`
becomes `organization-membership`. That is convenient, and it is *not* refactor-safe: renaming the
class changes the type, and the history splits in two at the rename. Derivation is for a prototype; a
declaration is for anything you intend to keep.

Two rules follow, and both fail loudly rather than quietly:

- **A type is unique.** Two audited entities deriving `invoice` would merge two unrelated histories,
  invisibly. The bundle refuses: a cache warmer inspects every mapped entity and throws
  `DuplicateAuditType` during `cache:warmup`, so the collision is a failed build and never a
  corrupted trail. Declare a distinct type on one of them.
- **A type is not inherited.** Unlike `enabled` — a policy a base class may reasonably impose —
  a name belongs to the class that declares it. A subclass gets its own derived type unless it
  declares one, precisely so two children never share one name.

Everything that reads the trail speaks types: `?entityType=invoice` over HTTP,
`forEntity('invoice', $id)` in the repository (which also accepts `Invoice::class` and converts it),
and `{"type": …, "id": …, "label": …}` inside a payload.

## Field policy

Properties are **tracked by default**. Opting a field out is a deliberate act, so a column added
next month is audited the day it appears rather than the day someone remembers to list it.

| Policy | Declared with | Value stored | Triggers a row |
| --- | --- | --- | --- |
| Tracked | nothing (default) | yes | yes |
| Masked | `#[AuditMasked]` | no — the mask sentinel | **yes** |
| Ignored | `#[NotAuditable]` | no | **no** |

Both attributes on the same property is a mistake, not a merge:
`ConflictingFieldPolicy::onProperty()` is thrown.

The lookup walks parent classes, so a private property declared on a mapped superclass keeps its
attributes.

## The row-trigger rule, precisely

For an **update**, given the Doctrine change set:

1. drop every key whose property is `#[NotAuditable]`;
2. let `R` = the remaining changed keys — tracked **or** masked;
3. write one entry **iff `R` is non-empty**;
4. the entry's `changes` contains exactly `R`: tracked keys with their real values, masked keys with
   the sentinel.

**Create and delete always write an entry**, regardless of `R`. There is no earlier row to
reconstruct a creation from, and no later row to explain a deletion.

Two consequences worth memorising:

- only-ignored changed ⇒ **no entry at all** (the change is still persisted, it is simply not an
  event);
- only-masked changed ⇒ **exactly one entry**, carrying sentinels.

## Worked example: a password-only change

```php
#[ORM\Entity]
#[Auditable]
class Account
{
    #[AuditLabel]
    #[ORM\Column(length: 180)]
    private string $email;

    #[AuditMasked]
    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[NotAuditable]
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;
}
```

```php
$account->changePassword($hasher->hash($plain));   // only $passwordHash changes
$em->flush();
```

One row, and it contains no hash:

```json
{
  "action": "update",
  "entity_type": "account",
  "entity_label": "ada@example.com",
  "changes": { "passwordHash": { "before": "********", "after": "********" } }
}
```

The sentinel is emitted from the mere **presence of the key** in the change set — the serializer
never reads the property. A secret cannot leak through this path even by accident, and the bundle's
own test asserts the plaintext appears nowhere in the whole table, not merely nowhere in that field.

Now bump `lastSeenAt` alone and flush: **nothing is recorded**. Bump `lastSeenAt` *and* the
password: one entry, containing only `passwordHash`.

On a create or delete the same field appears in the full-state payload as a bare sentinel:

```json
{ "email": "ada@example.com", "passwordHash": "********" }
```

## Labels

`#[AuditLabel]` designates the human title. It may sit on a property or on a public, parameterless
getter:

```php
#[AuditLabel]
#[ORM\Column(length: 255)]
private string $reference;
```

```php
#[AuditLabel]
public function excerpt(): string
{
    return mb_substr($this->message, 0, 20);
}
```

Resolution order: `#[AuditLabel]` → `__toString()` → nothing. The resolved value is **snapshotted**
onto every entry that names the entity — including entries that merely *reference* it through an
association — which is what keeps "assigned to Ada Lovelace" readable after Ada's row is gone.

A missing label is fine. Labels are resolved during the flush, so anything that would need a query
(an uninitialised lazy object, an uninitialised typed property) yields `null` rather than a load.
A label degrades an entry from readable to merely correct; a query mid-flush degrades the request.

Centralising labelling across a domain is a decoration, not an attribute on 200 classes — see
[extending.md](extending.md#label-resolver).

## Inheritance and `enabled: false`

`#[Auditable]` is read from the class and then up its parents, **nearest declaration wins**:

```php
#[Auditable]
abstract class Document { }              // every subclass is audited

class Contract extends Document { }      // audited, inherited

#[Auditable(enabled: false)]
class ImportScratchpad extends Document { }   // opted back out
```

`#[AuditScope]` follows the same nearest-wins walk. Field attributes are resolved per property, on
the class that declares the property.

There is no wildcard and no "audit everything" mode: an audited class is always one grep away.

## What the values look like

| PHP value | Stored as |
| --- | --- |
| `string`, `int`, `float`, `bool`, `null` | as-is (`null` included — "was set, now empty" is a change) |
| `\DateTimeInterface` | ISO-8601 with offset, e.g. `1843-10-01T00:00:00+00:00` |
| backed enum | its `->value` |
| pure enum | its `->name` |
| owning `ManyToOne`/`OneToOne` | `{"type": …, "id": …, "label": …}` — label snapshotted |
| other `\Stringable` value object | `(string) $value` |
| anything else (arrays, `stdClass`, closures) | **excluded**, and logged once at warning level |

Arrays — including JSON columns — are deliberately not in that list. A wrong `after` in an audit
trail is worse than a missing one. If you need them, register a serializer:
[extending.md](extending.md#value-serializer-a-value-object) and
[faq.md](faq.md#how-do-i-audit-a-json-column-safely).

Collection and many-to-many deltas are out of scope entirely — audit the owning child entity
instead. See [architecture.md](architecture.md#deliberately-out-of-scope).
