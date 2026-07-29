<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Exception;

final class ConflictingFieldPolicy extends \LogicException implements AuditTrailException
{
    public static function onProperty(string $class, string $property): self
    {
        return new self(\sprintf(
            'Property "%s::$%s" carries both #[NotAuditable] and #[AuditMasked]. Pick one: #[NotAuditable] drops '
            .'the change entirely, #[AuditMasked] records it without the value.',
            $class,
            $property,
        ));
    }
}
