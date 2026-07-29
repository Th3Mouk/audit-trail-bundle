<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * A subclass that wants a name of its own says so, and is the only way to get one.
 */
#[Auditable(type: 'the-child')]
final class TypedChildEntity extends TypedParentEntity
{
}
