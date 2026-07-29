<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail;

use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Context\RequestContext;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Metadata\AuditTypeResolver;
use Th3Mouk\AuditTrail\Model\Actor;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;

/**
 * Records what the ORM never saw.
 *
 * Everything the caller should not have to think about is filled in here: the actor comes
 * from the resolver chain, the timestamp is UTC, and the correlation identifier and client
 * address come from the ambient request context. The caller only says what happened.
 *
 * When the trail is switched off, every method is a no-op, so instrumentation can stay in
 * place in environments that do not want a trail.
 */
final readonly class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private AuditStorageInterface $storage,
        private ActorResolverInterface $actorResolver,
        private RequestContext $requestContext,
        private AuditTypeResolver $typeResolver,
        private bool $enabled = true,
        private ?Actor $forcedActor = null,
    ) {
    }

    /**
     * Attribute everything recorded through the returned logger to one principal.
     *
     * Imports, migrations and console commands know who they act for better than any
     * resolver can: `$this->audit->withActor(Actor::of($operator, 'import'))->created(...)`.
     */
    public function withActor(Actor $actor): self
    {
        return new self(
            $this->storage,
            $this->actorResolver,
            $this->requestContext,
            $this->typeResolver,
            $this->enabled,
            $actor,
        );
    }

    /**
     * `$entityType` accepts either a class name or a bare type.
     *
     * `Membership::class` is the natural thing to write, and it resolves to the same type capture
     * would have filed the entry under. But the paths this logger exists for do not always have a
     * class — a projection table, an OLAP row — so a string that is not a class is taken as the type
     * itself, and the entry simply records no class name.
     */
    public function record(
        AuditAction $action,
        string $entityType,
        string|int $entityId,
        ?array $changes = null,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void {
        if (!$this->enabled) {
            return;
        }

        $this->storage->store(new AuditEntry(
            $action,
            $this->typeResolver->typeOf($entityType),
            (string) $entityId,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            class_exists($entityType) ? $entityType : null,
            $this->forcedActor ?? $this->actorResolver->resolve() ?? Actor::unknown(),
            $entityLabel,
            $changes,
            $root,
            $this->requestContext->getRequestId(),
            $this->requestContext->getClientIp(),
            $metadata,
        ));
    }

    public function created(
        string $entityType,
        string|int $entityId,
        array $state,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void {
        $this->record(
            AuditAction::Create,
            $entityType,
            $entityId,
            self::payloadOrNull($state),
            $entityLabel,
            $root,
            $metadata,
        );
    }

    public function updated(
        string $entityType,
        string|int $entityId,
        array $changes,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void {
        $this->record(
            AuditAction::Update,
            $entityType,
            $entityId,
            self::payloadOrNull($changes),
            $entityLabel,
            $root,
            $metadata,
        );
    }

    public function deleted(
        string $entityType,
        string|int $entityId,
        array $stateAtDelete = [],
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void {
        $this->record(
            AuditAction::Delete,
            $entityType,
            $entityId,
            self::payloadOrNull($stateAtDelete),
            $entityLabel,
            $root,
            $metadata,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private static function payloadOrNull(array $payload): ?array
    {
        return [] === $payload ? null : $payload;
    }
}
