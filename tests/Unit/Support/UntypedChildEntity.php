<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

/**
 * Audited because its parent says so, named after itself because a name is not inherited.
 */
final class UntypedChildEntity extends TypedParentEntity
{
}
