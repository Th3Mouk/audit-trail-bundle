<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Exception;

final class AuditableEntityNotSupported extends \LogicException implements AuditTrailException
{
    public static function compositeIdentifier(string $class): self
    {
        return new self(\sprintf(
            'Entity "%s" is marked #[Auditable] but has a composite identifier. The audit trail stores a single '
            .'entity identifier; give the entity a single-column key or drop the attribute.',
            $class,
        ));
    }

    public static function noIdentifier(string $class): self
    {
        return new self(\sprintf(
            'Entity "%s" is marked #[Auditable] but exposes no identifier value at capture time.',
            $class,
        ));
    }
}
