<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Model;

use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * One recorded fact, independent of how it is stored.
 *
 * Capture produces these; a storage implementation persists them. Keeping the two apart
 * means the capture pipeline is unit-testable without a database, and an application can
 * swap storage without touching capture.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed>|null $changes
     * @param array<string, mixed>      $metadata
     */
    public function __construct(
        public AuditAction $action,
        public string $entityClass,
        public string $entityId,
        public \DateTimeImmutable $occurredAt,
        public ?Actor $actor = null,
        public ?string $entityLabel = null,
        public ?array $changes = null,
        public ?AuditScopeRef $root = null,
        public ?string $requestId = null,
        public ?string $ip = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->action,
            $this->entityClass,
            $this->entityId,
            $this->occurredAt,
            $this->actor,
            $this->entityLabel,
            $this->changes,
            $this->root,
            $this->requestId,
            $this->ip,
            [...$this->metadata, ...$metadata],
        );
    }

    public function withActor(?Actor $actor): self
    {
        return new self(
            $this->action,
            $this->entityClass,
            $this->entityId,
            $this->occurredAt,
            $actor,
            $this->entityLabel,
            $this->changes,
            $this->root,
            $this->requestId,
            $this->ip,
            $this->metadata,
        );
    }
}
