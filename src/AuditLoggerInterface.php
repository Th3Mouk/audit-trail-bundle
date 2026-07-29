<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;

/**
 * Record a change the ORM cannot see.
 *
 * Attribute-driven capture covers everything that flows through the entity manager.
 * Bulk DQL, raw SQL and side effects in other systems do not — inject this and say so
 * explicitly. Actor, timestamp and request context are filled in for you, exactly like a
 * logger.
 *
 * ```php
 * $this->audit->deleted(Membership::class, $id, $stateAtDelete, root: $userScope);
 * ```
 *
 * `$entityType` is the short name the entry is filed under. Hand it an entity class and it is
 * converted the same way capture would convert it, so a manual entry and a captured one land under
 * one type. Hand it a type of your own — `'invitation'`, `'stripe-subscription'` — for the things
 * that have no PHP class at all.
 */
interface AuditLoggerInterface
{
    /**
     * @param array<string, mixed>|null $changes
     * @param array<string, mixed>      $metadata
     */
    public function record(
        AuditAction $action,
        string $entityType,
        string|int $entityId,
        ?array $changes = null,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void;

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $metadata
     */
    public function created(
        string $entityType,
        string|int $entityId,
        array $state,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void;

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $metadata
     */
    public function updated(
        string $entityType,
        string|int $entityId,
        array $changes,
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void;

    /**
     * @param array<string, mixed> $stateAtDelete
     * @param array<string, mixed> $metadata
     */
    public function deleted(
        string $entityType,
        string|int $entityId,
        array $stateAtDelete = [],
        ?string $entityLabel = null,
        ?AuditScopeRef $root = null,
        array $metadata = [],
    ): void;
}
