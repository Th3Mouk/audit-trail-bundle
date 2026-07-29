<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Attribute;

/**
 * Exclude a property from the audit trail entirely.
 *
 * The value never reaches storage, and the property is stripped before the
 * row-trigger test — so a flush that changes *only* ignored properties records
 * nothing at all.
 *
 * Use this for transient secrets and for high-churn technical columns whose changes
 * carry no meaning. To record *that* a secret changed without storing its value,
 * use {@see AuditMasked} instead.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class NotAuditable
{
}
