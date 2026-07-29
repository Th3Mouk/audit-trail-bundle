<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Gate;

use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * Unanimity gate: a change is captured only when every registered gate agrees.
 *
 * Veto semantics are deliberate — a gate exists to silence noise, so one gate saying
 * "no" is enough and an application can add a gate without auditing the others. Order
 * only affects how early the chain short-circuits, never the outcome.
 *
 * Gates are collected from the `audit_trail.capture_gate` tag, highest priority first.
 */
final readonly class ChainCaptureGate implements CaptureGateInterface
{
    /**
     * @param iterable<CaptureGateInterface> $gates
     */
    public function __construct(
        private iterable $gates = [],
    ) {
    }

    public function shouldCapture(object $entity, AuditAction $action): bool
    {
        foreach ($this->gates as $gate) {
            if (!$gate->shouldCapture($entity, $action)) {
                return false;
            }
        }

        return true;
    }
}
