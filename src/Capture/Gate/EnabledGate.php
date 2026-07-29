<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Gate;

use Symfony\Contracts\Service\ResetInterface;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * The kill-switch, in both its forms.
 *
 * `audit_trail.enabled: false` turns capture off for the whole process. On top of that,
 * {@see self::pause()} silences capture at runtime so a bulk import or a data migration
 * can write thousands of rows without flooding the trail — no configuration change, no
 * listener juggling, and no way to lose the setting permanently: {@see self::resume()}
 * restores it, and a worker gets an implicit resume between messages through
 * {@see ResetInterface}, so a job that pauses and crashes cannot silence the next one.
 */
final class EnabledGate implements CaptureGateInterface, ResetInterface
{
    private bool $paused = false;

    public function __construct(
        private readonly bool $enabled = true,
    ) {
    }

    public function shouldCapture(object $entity, AuditAction $action): bool
    {
        return $this->enabled && !$this->paused;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function reset(): void
    {
        $this->paused = false;
    }
}
