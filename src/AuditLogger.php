<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail;

use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Context\RequestContext;
use Th3Mouk\AuditTrail\Enum\AuditAction;
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
            $this->enabled,
            $actor,
        );
    }

    public function record(
        AuditAction $action,
        string $entityClass,
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
            $entityClass,
            (string) $entityId,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
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
        string $entityClass,
        string|int $entityId,
        array $state,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void {
        $this->record(
            AuditAction::Create,
            $entityClass,
            $entityId,
            self::payloadOrNull($state),
            $entityLabel,
            $root,
            $metadata,
        );
    }

    public function updated(
        string $entityClass,
        string|int $entityId,
        array $changes,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void {
        $this->record(
            AuditAction::Update,
            $entityClass,
            $entityId,
            self::payloadOrNull($changes),
            $entityLabel,
            $root,
            $metadata,
        );
    }

    public function deleted(
        string $entityClass,
        string|int $entityId,
        array $stateAtDelete = [],
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void {
        $this->record(
            AuditAction::Delete,
            $entityClass,
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
