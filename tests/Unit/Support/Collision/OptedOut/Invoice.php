<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\OptedOut;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * A third `Invoice`, deriving the very same type as the other two and audited by none of it.
 * Nothing it does reaches the trail, so there is no history to merge and nothing to complain about.
 */
#[Auditable(enabled: false)]
final class Invoice
{
}
