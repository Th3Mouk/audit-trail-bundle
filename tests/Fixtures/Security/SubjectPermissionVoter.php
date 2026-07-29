<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * A permission voter of the kind real applications write: an attribute plus the thing it applies to.
 *
 * It exists to prove that the feed's `grants` configuration reaches
 * `is_granted($attribute, $subject)` as two arguments, so a host whose permissions are anchored on a
 * resource — "may this principal *view* *audit-logs*?" — can express that without a raw expression
 * and without the bundle knowing any permission name.
 */
final readonly class SubjectPermissionVoter implements VoterInterface
{
    public const string ATTRIBUTE = 'view';
    public const string SUBJECT = 'audit-logs';

    /**
     * @param list<string> $grantedTo usernames holding the permission
     */
    public function __construct(private array $grantedTo)
    {
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes, mixed ...$args): int
    {
        if (!\in_array(self::ATTRIBUTE, $attributes, true) || self::SUBJECT !== $subject) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();

        if (null === $user) {
            return self::ACCESS_DENIED;
        }

        return \in_array($user->getUserIdentifier(), $this->grantedTo, true)
            ? self::ACCESS_GRANTED
            : self::ACCESS_DENIED;
    }
}
