<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support\Collision;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * One of two classes that derive the same type. Deliberately not a mapped entity: the collision
 * guard is fed its metadata by the test, and a real duplicate would fail every other kernel's warmup.
 */
#[Auditable]
final class Invoice
{
}
