<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Enum;

enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
