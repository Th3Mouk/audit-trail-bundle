<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * Declares both that its children are audited and a name for itself. Only the first is inherited.
 */
#[Auditable(type: 'the-parent')]
abstract class TypedParentEntity
{
}
