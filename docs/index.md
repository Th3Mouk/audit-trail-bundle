# Documentation

Doctrine audit trail for Symfony: who changed what, and when. Field-level before/after diffs,
label snapshots that survive deletion, per-aggregate inline history, and pluggable actors.

## Start here

1. [Installation](installation.md) — install, register, create the table.
2. [Attributes](attributes.md) — mark one entity `#[Auditable]`, decide what each field does.
3. [Aggregate history](aggregate-history.md) — anchor entries to an aggregate and render a
   "history of this record" panel.
4. [Actor](actor.md) — teach the trail who your principals are.
5. [Configuration](configuration.md) — when the defaults stop fitting.

Everything else is reference material you can reach for when a specific question comes up.

## All pages

| Page | Answers |
| --- | --- |
| [installation.md](installation.md) | How do I install it and create the table? |
| [configuration.md](configuration.md) | What can I configure, and when should I? |
| [attributes.md](attributes.md) | What gets recorded, and how do I opt a field out or mask it? |
| [reading-the-trail.md](reading-the-trail.md) | Reading the trail from PHP: the repository, its four query builders, cursors, exports |
| [aggregate-history.md](aggregate-history.md) | How do I show "everything that happened to this record"? |
| [actor.md](actor.md) | How is the actor resolved, and how do I plug mine in? |
| [manual-logging.md](manual-logging.md) | How do I record a change Doctrine never saw? |
| [extending.md](extending.md) | Which seams exist, and how do I replace a default? |
| [bridges/gedmo.md](bridges/gedmo.md) | How are Gedmo translations and soft deletes handled? |
| [bridges/api-platform.md](bridges/api-platform.md) | How do I expose the trail over HTTP? |
| [architecture.md](architecture.md) | How does capture work, and what is out of scope? |
| [faq.md](faq.md) | Is it slow? How big does the table get? Can it be tampered with? |

## The shape of one entry

One table, one row per recorded fact:

| Column group | Meaning |
| --- | --- |
| `action`, `occurred_at` | what kind of change, and when |
| `entity_type`, `entity_id`, `entity_label` | what it happened to (label snapshotted) |
| `entity_class` | the class behind the type, when there is one — information, never a key |
| `actor_type`, `actor_id`, `actor_label` | who did it — all three optional |
| `changes` | the diff, or the full state for a create/delete |
| `root_type`, `root_id`, `root_label` | which aggregate it belongs to |
| `request_id`, `ip` | correlation id and client address |
| `metadata` | anything you added yourself |

Automatic capture and [manual logging](manual-logging.md) write the same payload shapes, so a
reader does not have to know which path produced a diff. They differ in one place:
`AuditLogListener` never reads the request context, so `request_id` and `ip` are null on captured
rows and filled only by the manual logger. A [storage decorator](extending.md#storage) is the seam
if you want them everywhere.
