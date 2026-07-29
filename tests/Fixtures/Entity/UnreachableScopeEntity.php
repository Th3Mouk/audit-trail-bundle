<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditScope;

/**
 * Names a first hop the class does not expose.
 */
#[Auditable]
#[AuditScope(root: Post::class, via: 'workspace.post')]
class UnreachableScopeEntity
{
}
