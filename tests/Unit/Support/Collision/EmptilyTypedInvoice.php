<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support\Collision;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * Declares a name that is not one. Files every entry under nothing, silently, until asked for.
 */
#[Auditable(type: '')]
final class EmptilyTypedInvoice
{
}
