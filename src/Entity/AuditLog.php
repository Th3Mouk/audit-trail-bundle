<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Repository\AuditLogRepository;

/**
 * One row of the trail.
 *
 * The identifier is a UUID v7: time-ordered, so it doubles as the feed's sort key and as
 * a keyset cursor, while inserting at the right edge of the index instead of scattering
 * across it like a random UUID would.
 *
 * Entries are discriminated by a short `entity_type` rather than by a class name. A class name is the
 * one column that would not survive a refactor, in a table built to outlive the rows it describes; the
 * class is kept beside the type as information, and nothing queries it.
 *
 * Labels are denormalised on purpose. An entry must remain readable when the rows it
 * points at are renamed or deleted, which no foreign key can promise.
 *
 * Each index ends with the feed's whole sort key, `(occurred_at, id)`, rather than with the timestamp
 * alone. Reads filter on one of the three prefixes and then order by that key, so carrying it in the
 * index is what lets the database walk the rows already sorted instead of collecting and sorting them
 * — which is the entire point of paginating by cursor.
 *
 * The table name is configurable; see the `audit_trail.table_name` option.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'idx_audit_entity', columns: ['entity_type', 'entity_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_audit_root', columns: ['root_type', 'root_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_type', 'actor_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_audit_occurred', columns: ['occurred_at', 'id'])]
class AuditLog
{
    /**
     * How long a discriminator may be, in both the entity and the root columns.
     *
     * A type is a short name by design, and the column says so. The build-time guard reads this
     * constant, so a type that would not fit is a failed warmup rather than an SQL error on the
     * first flush that happens to use it.
     */
    public const int TYPE_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'actor_type', length: 32, nullable: true)]
    private ?string $actorType;

    #[ORM\Column(name: 'actor_id', length: 64, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(name: 'actor_label', length: 255, nullable: true)]
    private ?string $actorLabel;

    #[ORM\Column(name: 'entity_type', length: self::TYPE_LENGTH)]
    private string $entityType;

    /**
     * Kept as information, never as a key: nothing filters or joins on it, and it is null for an
     * entry recorded against something that has no PHP class at all.
     */
    #[ORM\Column(name: 'entity_class', length: 255, nullable: true)]
    private ?string $entityClass;

    #[ORM\Column(name: 'entity_id', length: 64)]
    private string $entityId;

    #[ORM\Column(name: 'entity_label', length: 255, nullable: true)]
    private ?string $entityLabel;

    #[ORM\Column(length: 16, enumType: AuditAction::class)]
    private AuditAction $action;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes;

    #[ORM\Column(name: 'root_type', length: self::TYPE_LENGTH, nullable: true)]
    private ?string $rootType;

    #[ORM\Column(name: 'root_id', length: 64, nullable: true)]
    private ?string $rootId;

    #[ORM\Column(name: 'root_label', length: 255, nullable: true)]
    private ?string $rootLabel;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'request_id', length: 64, nullable: true)]
    private ?string $requestId;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata;

    private function __construct(Uuid $id, AuditEntry $entry)
    {
        $this->id = $id;
        $this->actorType = $entry->actor?->type;
        $this->actorId = $entry->actor?->id;
        $this->actorLabel = $entry->actor?->label;
        $this->entityType = $entry->entityType;
        $this->entityClass = $entry->entityClass;
        $this->entityId = $entry->entityId;
        $this->entityLabel = $entry->entityLabel;
        $this->action = $entry->action;
        $this->changes = $entry->changes;
        $this->rootType = $entry->root?->type;
        $this->rootId = $entry->root?->id;
        $this->rootLabel = $entry->root?->label;
        $this->occurredAt = $entry->occurredAt;
        $this->requestId = $entry->requestId;
        $this->ip = $entry->ip;
        $this->metadata = [] === $entry->metadata ? null : $entry->metadata;
    }

    public static function fromEntry(AuditEntry $entry): self
    {
        return new self(Uuid::v7(), $entry);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActorType(): ?string
    {
        return $this->actorType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getActorLabel(): ?string
    {
        return $this->actorLabel;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getEntityLabel(): ?string
    {
        return $this->entityLabel;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    public function getRootType(): ?string
    {
        return $this->rootType;
    }

    public function getRootId(): ?string
    {
        return $this->rootId;
    }

    public function getRootLabel(): ?string
    {
        return $this->rootLabel;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
}
