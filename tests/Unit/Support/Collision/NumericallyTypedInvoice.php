<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support\Collision;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * Declares a type PHP would rather store as an integer array key.
 */
#[Auditable(type: '2024')]
final class NumericallyTypedInvoice
{
}
