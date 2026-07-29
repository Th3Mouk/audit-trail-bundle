<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * First non-null answer wins; no answer at all leaves the caller's own action standing.
 *
 * Unlike a capture gate, this chain is not unanimous: reclassifying an update is a positive
 * claim about what happened, and two resolvers claiming different things is a configuration
 * mistake, not something to arbitrate at runtime. Priority decides, and the shipped chain is
 * empty unless a bridge or an application fills it.
 *
 * Resolvers are collected from the `audit_trail.action_resolver` tag, highest priority first.
 */
final readonly class ChainActionResolver implements ActionResolverInterface
{
    /**
     * @param iterable<ActionResolverInterface> $resolvers
     */
    public function __construct(
        private iterable $resolvers = [],
    ) {
    }

    public function resolveAction(object $entity, array $changeSet): ?AuditAction
    {
        foreach ($this->resolvers as $resolver) {
            $action = $resolver->resolveAction($entity, $changeSet);

            if (null !== $action) {
                return $action;
            }
        }

        return null;
    }
}
