<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Model\Actor;

/**
 * Names the actor of a capture without an authenticated session.
 *
 * `deferring()` returns null, which is how a test proves the chain moves on to the next
 * resolver. Registered in every test kernel so an integration test can attribute a flush
 * to whoever the scenario needs, mid-test, with `will()`.
 */
final class FakeActorResolver implements ActorResolverInterface
{
    private int $calls = 0;

    public function __construct(
        private ?Actor $actor = null,
    ) {
    }

    public static function returning(Actor $actor): self
    {
        return new self($actor);
    }

    public static function deferring(): self
    {
        return new self();
    }

    public function will(?Actor $actor): void
    {
        $this->actor = $actor;
    }

    public function resolve(): ?Actor
    {
        ++$this->calls;

        return $this->actor;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function wasNeverAsked(): bool
    {
        return 0 === $this->calls;
    }
}
