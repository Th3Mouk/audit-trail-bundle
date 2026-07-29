<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Metadata;

/**
 * How a single property participates in the audit trail.
 *
 * The distinction that matters is row-triggering: Masked still produces an entry,
 * Ignored does not. See {@see \Th3Mouk\AuditTrail\Attribute\AuditMasked}.
 */
enum FieldPolicy
{
    case Tracked;
    case Masked;
    case Ignored;

    public function isRowTriggering(): bool
    {
        return self::Ignored !== $this;
    }

    public function recordsValue(): bool
    {
        return self::Tracked === $this;
    }
}
