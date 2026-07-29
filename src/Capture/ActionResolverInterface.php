<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * Reclassifies a scheduled UPDATE that is really something else.
 *
 * Doctrine only knows three shapes — insert, update, delete — and a library sitting on top of
 * it can turn one into another before capture ever sees it. A logical delete is the archetype:
 * the row is updated, but what happened is a deletion, and the change set is the only evidence
 * left.
 *
 * `null` means "no opinion", which is what every resolver returns for every entity it knows
 * nothing about; the first non-null answer wins and the capture listener falls back to
 * `AuditAction::Update`. A resolver is consulted for scheduled updates only: insertions and
 * deletions are already unambiguous.
 *
 * Consulted inside `onFlush`, so the same two rules as every other capture seam apply: read
 * the change set that is handed to you, issue no query, initialise no association.
 *
 * Register an implementation with the `audit_trail.action_resolver` tag; `priority` orders the
 * chain, highest first.
 */
interface ActionResolverInterface
{
    /**
     * @param array<string, array<int, mixed>> $changeSet the entity's Doctrine change set, field => [before, after]
     */
    public function resolveAction(object $entity, array $changeSet): ?AuditAction;
}
