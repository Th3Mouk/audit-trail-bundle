<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Value;

use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;

/**
 * Backed enums store their value — the same token the column holds — pure enums store their name.
 */
final readonly class EnumValueSerializer implements ValueSerializerInterface
{
    public function supports(mixed $value): bool
    {
        return $value instanceof \UnitEnum;
    }

    public function serialize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => null,
        };
    }
}
