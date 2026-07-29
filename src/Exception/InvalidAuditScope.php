<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Exception;

final class InvalidAuditScope extends \LogicException implements AuditTrailException
{
    public static function rootMismatch(string $entityClass, string $declared, string $actual): self
    {
        return new self(\sprintf(
            'The #[AuditScope] of "%s" declares root "%s" but its path resolves to "%s".',
            $entityClass,
            $declared,
            $actual,
        ));
    }

    public static function unreachableSegment(string $entityClass, string $via, string $segment): self
    {
        return new self(\sprintf(
            'The #[AuditScope] path "%s" of "%s" cannot be walked: no readable "%s" on the current step.',
            $via,
            $entityClass,
            $segment,
        ));
    }

    public static function emptyPath(string $entityClass): self
    {
        return new self(\sprintf('The #[AuditScope] of "%s" declares an empty path.', $entityClass));
    }
}
