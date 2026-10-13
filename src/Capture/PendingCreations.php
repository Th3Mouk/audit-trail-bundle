<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Symfony\Contracts\Service\ResetInterface;
use Th3Mouk\AuditTrail\Model\Actor;

/**
 * The creations of one flush that are waiting for their INSERT to give them a key.
 *
 * Held by entity, weakly: a flush that fails before its INSERTs leaves nothing behind that keeps an
 * entity alive, and one object can never be mistaken for another that reuses its `spl_object_id`.
 * Anything left over from such a flush is dropped when the next one starts.
 *
 * The actor is the one the flush resolved, once; it is attached to a creation when it is released
 * rather than asked again for each of them.
 */
final class PendingCreations implements ResetInterface
{
    /** @var \WeakMap<object, array{class-string, \DateTimeImmutable}> */
    private \WeakMap $pending;

    private ?Actor $actor = null;

    public function __construct()
    {
        $this->pending = new \WeakMap();
    }

    /**
     * @param class-string $entityClass
     */
    public function defer(object $entity, string $entityClass, \DateTimeImmutable $occurredAt): void
    {
        $this->pending[$entity] = [$entityClass, $occurredAt];
    }

    public function isEmpty(): bool
    {
        return 0 === \count($this->pending);
    }

    public function attributeTo(?Actor $actor): void
    {
        $this->actor = $actor;
    }

    public function release(object $entity): ?PendingCreation
    {
        if (!isset($this->pending[$entity])) {
            return null;
        }

        [$entityClass, $occurredAt] = $this->pending[$entity];
        unset($this->pending[$entity]);

        return new PendingCreation($entityClass, $occurredAt, $this->actor);
    }

    public function discard(): void
    {
        $this->pending = new \WeakMap();
        $this->actor = null;
    }

    public function reset(): void
    {
        $this->discard();
    }
}
