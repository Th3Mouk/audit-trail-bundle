<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Exception;

final class UnsupportedApiPlatformVersion extends \LogicException implements AuditTrailException
{
    public static function forTheFeed(string $installed, string $minimum): self
    {
        return new self(\sprintf(
            'The API Platform audit feed needs api-platform/core >= %s, and %s is installed. The feed '
            .'paginates by a cursor covering `occurredAt`, and older versions cast that date to a string '
            .'when building the `hydra:next` link, which fails on the very first page. Either upgrade '
            .'api-platform/core, or set audit_trail.bridges.api_platform.enabled to false and read the '
            .'trail through AuditLogRepository instead.',
            $minimum,
            $installed,
        ));
    }
}
