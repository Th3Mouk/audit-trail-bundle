<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Th3Mouk\AuditTrail\Capture\ActionResolverInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * A resolver whose answer a test states outright.
 *
 * `abstaining()` is the interesting one: "no opinion" is what every resolver returns for almost
 * every entity, so a chain that stops at the first abstention would be broken in the shape
 * nobody notices. Whether the resolver was consulted at all is recorded, which is how a test
 * proves the chain short-circuits on the first real answer.
 */
final class FakeActionResolver implements ActionResolverInterface
{
    private bool $asked = false;

    private function __construct(
        private readonly ?AuditAction $answer,
    ) {
    }

    public static function abstaining(): self
    {
        return new self(null);
    }

    public static function answering(AuditAction $action): self
    {
        return new self($action);
    }

    public function resolveAction(object $entity, array $changeSet): ?AuditAction
    {
        $this->asked = true;

        return $this->answer;
    }

    public function wasAsked(): bool
    {
        return $this->asked;
    }
}
