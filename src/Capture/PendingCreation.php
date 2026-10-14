<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Th3Mouk\AuditTrail\Model\Actor;

/**
 * What `onFlush` decided about a creation whose key does not exist yet.
 *
 * Only the decisions are kept — that it is captured, as which class, when, and for whom. The
 * entry itself is built once the INSERT has given the entity its key, because the key is not the
 * only thing that needs it: a reference to another new entity, or an aggregate root that is one,
 * resolves to nothing until then.
 */
final readonly class PendingCreation
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        public string $entityClass,
        public \DateTimeImmutable $occurredAt,
        public ?Actor $actor,
    ) {
    }
}
