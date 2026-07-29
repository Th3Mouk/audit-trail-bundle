<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Scope;

use Th3Mouk\AuditTrail\Model\AuditRef;

/**
 * Escape hatch for aggregate roots a getter chain cannot express.
 *
 * Implement this on an audited entity when the root is conditional, computed or
 * polymorphic. It takes precedence over {@see \Th3Mouk\AuditTrail\Attribute\AuditScope}.
 *
 * Implementations run inside the flush: they MUST NOT query the database. Build the
 * reference from data already in memory, or return null.
 */
interface AuditScopeProviderInterface
{
    public function resolveAuditRoot(): ?AuditRef;
}
