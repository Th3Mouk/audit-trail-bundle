<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Case;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\Actor;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * Builders that let a test state only what it is about.
 *
 * Every parameter has a defensible default, so a test that cares about the change set says
 * nothing about actors or timestamps, and a test about actors says nothing about changes.
 * Pass arguments by name.
 */
trait AuditEntryBuilders
{
    /**
     * @param array<string, mixed>|null $changes
     * @param array<string, mixed>      $metadata
     */
    protected function anEntry(
        AuditAction $action = AuditAction::Update,
        string $entityClass = Post::class,
        string|int $entityId = 1,
        ?array $changes = null,
        ?string $entityLabel = null,
        ?Actor $actor = null,
        ?AuditScopeRef $root = null,
        ?\DateTimeImmutable $occurredAt = null,
        ?string $requestId = null,
        ?string $ip = null,
        array $metadata = [],
    ): AuditEntry {
        return new AuditEntry(
            $action,
            $entityClass,
            (string) $entityId,
            $occurredAt ?? $this->anInstant(),
            $actor,
            $entityLabel,
            $changes,
            $root,
            $requestId,
            $ip,
            $metadata,
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function aCreatedEntry(
        string $entityClass = Post::class,
        string|int $entityId = 1,
        array $state = [],
        ?string $entityLabel = null,
        ?Actor $actor = null,
        ?AuditScopeRef $root = null,
    ): AuditEntry {
        return $this->anEntry(
            action: AuditAction::Create,
            entityClass: $entityClass,
            entityId: $entityId,
            changes: $state,
            entityLabel: $entityLabel,
            actor: $actor,
            root: $root,
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    protected function anUpdatedEntry(
        string $entityClass = Post::class,
        string|int $entityId = 1,
        array $changes = [],
        ?string $entityLabel = null,
        ?Actor $actor = null,
        ?AuditScopeRef $root = null,
    ): AuditEntry {
        return $this->anEntry(
            action: AuditAction::Update,
            entityClass: $entityClass,
            entityId: $entityId,
            changes: $changes,
            entityLabel: $entityLabel,
            actor: $actor,
            root: $root,
        );
    }

    /**
     * @param array<string, mixed> $stateAtDelete
     */
    protected function aDeletedEntry(
        string $entityClass = Post::class,
        string|int $entityId = 1,
        array $stateAtDelete = [],
        ?string $entityLabel = null,
        ?Actor $actor = null,
        ?AuditScopeRef $root = null,
    ): AuditEntry {
        return $this->anEntry(
            action: AuditAction::Delete,
            entityClass: $entityClass,
            entityId: $entityId,
            changes: $stateAtDelete,
            entityLabel: $entityLabel,
            actor: $actor,
            root: $root,
        );
    }

    protected function anActor(
        ?string $id = '42',
        ?string $type = 'fixture',
        ?string $label = 'Fixture Actor',
    ): Actor {
        return new Actor($id, $type, $label);
    }

    protected function aRef(
        string $class = Post::class,
        string|int $id = 1,
        ?string $label = null,
    ): AuditRef {
        return AuditRef::of($class, $id, $label);
    }

    protected function aScopeRef(
        string $type = 'post',
        string|int $id = 1,
        ?string $label = null,
    ): AuditScopeRef {
        return AuditScopeRef::of($type, $id, $label);
    }

    /**
     * @return array{before: mixed, after: mixed}
     */
    protected function aChange(mixed $before, mixed $after): array
    {
        return ['before' => $before, 'after' => $after];
    }

    protected function anInstant(string $expression = '2026-01-01 12:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($expression, new \DateTimeZone('UTC'));
    }
}
