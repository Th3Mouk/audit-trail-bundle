<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Repository;

use Symfony\Component\Uid\Uuid;
use Th3Mouk\AuditTrail\Entity\AuditLog;

/**
 * The position of one entry in the feed's order — both halves of it.
 *
 * The feed is sorted by `(occurredAt DESC, id DESC)`, so a cursor has to carry both. The identifier
 * alone looks sufficient, because a UUID v7 is time-ordered — but only for entries the listener
 * captured. A backfill writes historical timestamps under identifiers minted today, and from then on
 * `id DESC` and `occurredAt DESC` are two different orders: paging by the identifier would silently
 * skip and repeat rows, and the indexes, which end with `(occurred_at, id)`, would not serve the
 * query anyway.
 *
 * Pass the last entry you displayed:
 *
 *     $next = $repository->forRoot('organization', $id, AuditCursor::of($lastDisplayed));
 */
final readonly class AuditCursor
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public Uuid $id,
    ) {
    }

    public static function of(AuditLog $entry): self
    {
        return new self($entry->getOccurredAt(), $entry->getId());
    }
}
