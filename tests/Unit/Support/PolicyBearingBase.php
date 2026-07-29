<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditMasked;
use Th3Mouk\AuditTrail\Attribute\NotAuditable;

/**
 * Declares its field policies on private properties, so a subclass proves the policy lookup
 * walks parents instead of stopping at the class it was handed.
 */
#[Auditable]
abstract class PolicyBearingBase
{
    #[AuditMasked]
    private string $token = '';

    #[NotAuditable]
    private string $trace = '';

    private string $subject = '';

    public function getToken(): string
    {
        return $this->token;
    }

    public function getTrace(): string
    {
        return $this->trace;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }
}
