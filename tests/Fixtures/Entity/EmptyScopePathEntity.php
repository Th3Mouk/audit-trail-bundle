<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditScope;

/**
 * Declares a root reachable through nothing at all.
 */
#[Auditable]
#[AuditScope(root: Post::class, via: '')]
class EmptyScopePathEntity
{
}
