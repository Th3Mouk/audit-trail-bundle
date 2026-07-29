<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * Last word on whether a candidate change is recorded.
 *
 * Every registered gate must agree. This is the seam for suppressing noise the
 * attributes cannot express: silencing a bulk import, dropping cascade-deleted children
 * whose aggregate root is already being recorded, or pausing capture entirely.
 *
 * Register an implementation with the `audit_trail.capture_gate` tag.
 */
interface CaptureGateInterface
{
    public function shouldCapture(object $entity, AuditAction $action): bool;
}
