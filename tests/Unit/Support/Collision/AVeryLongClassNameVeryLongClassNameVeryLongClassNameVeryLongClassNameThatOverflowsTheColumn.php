<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support\Collision;

use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * Nobody declared a type here, so one is derived from this name — and it does not fit the column.
 * Without the guard, the first entry recorded for it fails at the database instead.
 */
#[Auditable]
final class AVeryLongClassNameVeryLongClassNameVeryLongClassNameVeryLongClassNameThatOverflowsTheColumn
{
}
