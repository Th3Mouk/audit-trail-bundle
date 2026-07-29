<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Exception;

final class DuplicateAuditType extends \LogicException implements AuditTrailException
{
    /**
     * @param list<class-string> $claimants
     */
    public static function claimedBy(string $type, array $claimants): self
    {
        return new self(\sprintf(
            'The audit type "%s" is claimed by %s. Entries are discriminated by type, so two classes '
            .'sharing one would merge two unrelated histories without saying so. Declare a distinct '
            .'type on at least one of them: #[Auditable(type: \'…\')].',
            $type,
            implode(' and ', $claimants),
        ));
    }
}
