<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Actor;

use Th3Mouk\AuditTrail\Model\Actor;

/**
 * Asks each registered resolver, in priority order, until one claims the change.
 *
 * Nothing is remembered between calls. A worker that handles a thousand messages resolves
 * the actor a thousand times, so message #2 can never inherit message #1's principal.
 *
 * When no resolver answers, the change is still recorded — attributed to nobody.
 */
final readonly class ChainActorResolver implements ActorResolverInterface
{
    /**
     * @param iterable<ActorResolverInterface> $resolvers
     */
    public function __construct(
        private iterable $resolvers = [],
    ) {
    }

    public function resolve(): Actor
    {
        foreach ($this->resolvers as $resolver) {
            $actor = $resolver->resolve();

            if (null !== $actor) {
                return $actor;
            }
        }

        return Actor::unknown();
    }
}
