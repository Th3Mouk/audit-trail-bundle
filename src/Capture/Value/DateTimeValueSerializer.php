<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Value;

use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;

/**
 * Dates as ISO-8601 with their offset, so a stored diff stays unambiguous across timezones.
 */
final readonly class DateTimeValueSerializer implements ValueSerializerInterface
{
    public function supports(mixed $value): bool
    {
        return $value instanceof \DateTimeInterface;
    }

    public function serialize(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : null;
    }
}
