<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\Legacy;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * The way out of a collision: one of the two claims a name of its own.
 */
#[Auditable(type: 'legacy-invoice')]
final class SettledInvoice
{
}
