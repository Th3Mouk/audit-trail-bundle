<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Th3Mouk\AuditTrail\Model\AuditRef;

/**
 * Resolves the aggregate root an entity's entries belong to.
 *
 * Implementations run inside the flush and MUST NOT query the database: walk only
 * already-known identifiers and initialised objects, and return null when the root
 * cannot be determined without I/O.
 */
interface ScopeResolverInterface
{
    public function resolve(object $entity): ?AuditRef;

    public function resolveType(object $entity): ?string;
}
