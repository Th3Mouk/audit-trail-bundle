<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Exception;

final class InvalidActor extends \InvalidArgumentException implements AuditTrailException
{
    public static function typeTooLong(string $type, int $maxLength): self
    {
        return new self(\sprintf('Actor type "%s" exceeds the %d characters the trail stores.', $type, $maxLength));
    }

    public static function idTooLong(string $id, int $maxLength): self
    {
        return new self(\sprintf('Actor identifier "%s" exceeds the %d characters the trail stores.', $id, $maxLength));
    }
}
