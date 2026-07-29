<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Enum;

/**
 * A pure enum: it has no backing value, so the trail records its `name`.
 */
enum PostVisibility
{
    case Public;
    case Private;
}
