<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Attribute;

/**
 * Record that a property changed, without storing its value.
 *
 * The serializer never reads the value: it emits the mask purely from the property
 * being present in the change set. A password hash therefore never reaches the
 * audit table, yet "the password was changed" is still recorded.
 *
 * Unlike {@see NotAuditable}, a masked property IS row-triggering: an update whose
 * only change is masked still produces an audit entry.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class AuditMasked
{
    public function __construct(
        public ?string $mask = null,
    ) {
    }
}
