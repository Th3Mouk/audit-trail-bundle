# The actor

## No taxonomy, no user class

The bundle has **no opinion about what a principal is**. There is no `ActorType` enum, no built-in
`'user'` / `'system'` vocabulary to conform to, and no assumption that your application has a `User`
class — or only one.

An actor is three optional strings:

```php
final readonly class Actor
{
    public function __construct(
        public ?string $id = null,     // max 64 chars
        public ?string $type = null,   // max 32 chars — a free label you choose
        public ?string $label = null,  // a snapshot, so it survives a rename or a delete
    ) {}
}
```

`type` is yours to define, or to ignore entirely. Use it if your trail needs to tell kinds of
principals apart (`'human'`, `'service_account'`, `'cron'`); leave it null if it does not. The
identifier is a string so principals with unrelated key types can coexist in one trail. `label` is
snapshotted for the same reason entity labels are: "Grace Hopper deleted the invoice" has to stay
readable after Grace's row is gone.

Three helpers, and that is the whole API:

| | |
| --- | --- |
| `Actor::of(string\|int\|null $id, ?string $type = null, ?string $label = null)` | the usual constructor — casts an integer key to string for you |
| `Actor::unknown()` | all three fields null; nobody could be attributed |
| `$actor->isKnown()` | false only when all three are null |

Over-long values are rejected loudly (`InvalidActor::typeTooLong()` / `idTooLong()`) rather than
truncated into something that no longer identifies anyone.

## How it is resolved

One chain, consulted on every entry, highest priority first:

```
ChainActorResolver
  └─ your resolvers          (tag: audit_trail.actor_resolver, 'priority' attribute)
  └─ SecurityTokenActorResolver   (priority -100, registered only if security is installed)
  └─ Actor::unknown()             (nothing claimed it)
```

Two properties matter:

- **stateless.** The actor is re-resolved for every call. A worker handling a thousand messages
  resolves a thousand times, so message #2 can never inherit message #1's principal.
- **`null` defers.** A resolver that does not recognise the situation returns `null` and the next one
  is asked. Only claim what you know.

The chain is exposed as the public alias `ActorResolverInterface`, so you can inject it or replace it
wholesale.

## The default: `SecurityTokenActorResolver`

Registered automatically — and only — when `symfony/security-core` is installed **and** the
application has a `security` extension. Absent either, it never enters the container, and the bundle
still boots (this is asserted by `StandaloneBundleTest`).

What it reads, by default, is the single thing every Symfony user object exposes:
`getUserIdentifier()`. Nothing else. It does not guess an `id`, does not look for `getName()`, and
does not invent a `type`.

You can teach it your class without writing a resolver: it accepts an actor type and two closures — one
deriving the identifier, one deriving the label. A compiled container cannot hold a closure argument,
so pass them from a small factory:

```php
namespace App\Audit;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Th3Mouk\AuditTrail\Actor\SecurityTokenActorResolver;

final class SecurityActorResolverFactory
{
    public static function create(TokenStorageInterface $tokenStorage): SecurityTokenActorResolver
    {
        return new SecurityTokenActorResolver(
            $tokenStorage,
            'human',
            static fn (object $user): ?string => $user instanceof User ? (string) $user->getId() : null,
            static fn (object $user): ?string => $user instanceof User ? $user->getFullName() : null,
        );
    }
}
```

```yaml
# config/services.yaml
services:
    Th3Mouk\AuditTrail\Actor\SecurityTokenActorResolver:
        factory: [App\Audit\SecurityActorResolverFactory, create]
        arguments: ['@security.token_storage']
        tags:
            - { name: audit_trail.actor_resolver, priority: -100 }
```

Redefining the service id replaces the one the bundle registered. If that feels like a lot of
ceremony for two closures, [write your own resolver](#writing-your-own-resolver) instead — it is the
same amount of code and reads better.

### Impersonation

A change made while an administrator is impersonating a customer is attributed to **the
administrator** — the real human who pressed the button. `SwitchUserToken` is unwrapped and the
original token's user is used.

Who was being impersonated is available separately, so you can put it where it belongs — in the
entry's metadata — instead of losing it or lying about the actor:

```php
$impersonated = $this->securityActor->resolveImpersonatedIdentifier();

$this->audit->updated(
    Subscription::class,
    $id,
    $changes,
    metadata: null === $impersonated ? [] : ['impersonating' => $impersonated],
);
```

(Automatic capture does not add metadata on its own; use a resolver plus
[manual logging](manual-logging.md), or your own storage decorator, if you want it on every entry.)

## Writing your own resolver

```php
namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Model\Actor;

#[AutoconfigureTag('audit_trail.actor_resolver', ['priority' => 100])]
final readonly class ApiKeyActorResolver implements ActorResolverInterface
{
    public function __construct(private RequestStack $requestStack) {}

    public function resolve(): ?Actor
    {
        $client = $this->requestStack->getCurrentRequest()?->attributes->get('_api_client');

        return $client instanceof ApiClient
            ? Actor::of($client->getId(), 'service_account', $client->getName())
            : null;
    }
}
```

Higher priority runs first, so an API-key resolver at `100` claims machine calls before the security
resolver (`-100`) sees them. Return `null` for anything you do not recognise.

Rules for an implementation:

- **stateless** — no memoisation across calls;
- **cheap** — it runs once per flush that produces entries;
- **never throws on "no principal"** — return `null`.

A queue-worker resolver typically reads whatever your message envelope carries:

```php
public function resolve(): ?Actor
{
    $stamp = $this->context->currentActorStamp();   // your own ambient holder

    return null === $stamp ? null : Actor::of($stamp->id, $stamp->type, $stamp->label);
}
```

## "Unknown actor" is a legitimate outcome

When nothing claims the change, the entry is written with all three columns null. This is correct,
not a gap: a console command, a data migration, a queue worker or a cron job frequently has no
principal, and a trail that refused to record those changes would be worse than one that records
them as unattributed.

If a specific command *does* know who it acts for, say so explicitly rather than inventing a
resolver:

```php
$this->audit
    ->withActor(Actor::of($operator, 'import', 'Nightly ERP import'))
    ->created(Product::class, $id, $state);
```

`withActor()` lives on the concrete `AuditLogger` (returning a new instance that forces the actor),
so type-hint `AuditLogger` where you need it — see [manual-logging.md](manual-logging.md).

To find unattributed changes: `forActor(null, null)` does **not** mean "where actor is null" — a null
argument means "do not constrain that column". Query the table directly for that report.
