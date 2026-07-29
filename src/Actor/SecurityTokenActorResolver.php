<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Actor;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Th3Mouk\AuditTrail\Model\Actor;

/**
 * Reads the current principal from the security token, without knowing your user class.
 *
 * The bundle refuses to guess what a user is. By default the identifier is whatever
 * `getUserIdentifier()` returns and nothing else is read; supply closures to derive the
 * identifier or a human label from your own class, and a type string if your trail needs
 * to tell kinds of principals apart.
 *
 * The token storage is optional: with symfony/security-core absent — or simply no
 * firewall — this resolver stays silent and the chain moves on.
 *
 * Impersonation is attributed to the real human: an administrator acting as a customer is
 * recorded as the administrator, and {@see resolveImpersonatedIdentifier()} exposes who
 * was being impersonated so a caller can put it in the entry metadata.
 */
final readonly class SecurityTokenActorResolver implements ActorResolverInterface
{
    /**
     * @param (\Closure(object): (string|int|null))|null $idResolver
     * @param (\Closure(object): (string|null))|null     $labelResolver
     */
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
        private ?string $actorType = null,
        private ?\Closure $idResolver = null,
        private ?\Closure $labelResolver = null,
    ) {
    }

    public function resolve(): ?Actor
    {
        $user = $this->attributedUser();

        if (null === $user) {
            return null;
        }

        return Actor::of($this->identifierOf($user), $this->actorType, $this->labelOf($user));
    }

    public function resolveImpersonatedIdentifier(): ?string
    {
        $token = $this->currentToken();

        if (!$this->isImpersonation($token)) {
            return null;
        }

        $impersonated = $token->getUser();

        if (null === $impersonated) {
            return null;
        }

        $identifier = $this->identifierOf($impersonated);

        return null === $identifier ? null : (string) $identifier;
    }

    private function attributedUser(): ?object
    {
        $token = $this->currentToken();

        if (null === $token) {
            return null;
        }

        if ($this->isImpersonation($token)) {
            return $token->getOriginalToken()->getUser();
        }

        return $token->getUser();
    }

    private function currentToken(): ?TokenInterface
    {
        return $this->tokenStorage?->getToken();
    }

    /**
     * @phpstan-assert-if-true SwitchUserToken $token
     */
    private function isImpersonation(?TokenInterface $token): bool
    {
        return null !== $token
            && class_exists(SwitchUserToken::class)
            && $token instanceof SwitchUserToken;
    }

    private function identifierOf(object $user): string|int|null
    {
        if (null !== $this->idResolver) {
            return ($this->idResolver)($user);
        }

        return method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : null;
    }

    private function labelOf(object $user): ?string
    {
        return null === $this->labelResolver ? null : ($this->labelResolver)($user);
    }
}
