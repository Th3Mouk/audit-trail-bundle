<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Exception;

final class InvalidAuditType extends \LogicException implements AuditTrailException
{
    public static function empty(string $class): self
    {
        return new self(\sprintf(
            'The audit type of %s is an empty string. A type is what entries are filed and queried '
            .'under, so an empty one files them under nothing. Declare a name — '
            .'#[Auditable(type: \'…\')] — or leave `type` out entirely and let it be derived from '
            .'the class name.',
            $class,
        ));
    }

    public static function tooLong(string $class, string $type, int $limit): self
    {
        return new self(\sprintf(
            'The audit type of %s is %d characters long ("%s"), and the column holds %d. A type is a '
            .'short name on purpose; declare a shorter one with #[Auditable(type: \'…\')]. Left as is, '
            .'the first entry recorded for this class would fail at the database rather than here.',
            $class,
            mb_strlen($type),
            $type,
            $limit,
        ));
    }
}
