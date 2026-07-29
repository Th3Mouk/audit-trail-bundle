<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\Legacy;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * The other half of the collision: a different namespace, the same short name, so the same
 * derived type — the ordinary outcome of two modules each having an `Invoice`.
 */
#[Auditable]
final class Invoice
{
}
