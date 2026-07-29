<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Value;

use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;

/**
 * Values JSON already understands, including null — "was set, now empty" is a change worth keeping.
 */
final readonly class ScalarValueSerializer implements ValueSerializerInterface
{
    public function supports(mixed $value): bool
    {
        return null === $value || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value);
    }

    public function serialize(mixed $value): mixed
    {
        return $value;
    }
}
