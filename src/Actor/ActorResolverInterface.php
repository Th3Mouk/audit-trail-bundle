<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Actor;

use Th3Mouk\AuditTrail\Model\Actor;

/**
 * Resolves who is performing the current change.
 *
 * Implementations are consulted in priority order and MUST be stateless: the actor is
 * re-read on every call so a long-running worker never attributes one job's changes to
 * another job's principal. Return null to defer to the next resolver.
 *
 * Register an implementation with the `audit_trail.actor_resolver` tag.
 */
interface ActorResolverInterface
{
    public function resolve(): ?Actor;
}
