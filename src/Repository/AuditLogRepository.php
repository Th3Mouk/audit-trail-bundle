<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Metadata\AuditTypeResolver;

/**
 * Reading the trail, one page at a time.
 *
 * Every helper returns a builder in the feed's one canonical order, `(occurredAt DESC, id DESC)` —
 * the same order the HTTP feed serves and the same key every index ends with, so a page is a range
 * scan rather than a sort. Paging asks for the entries *before* a known position instead of counting
 * rows to skip: a trail only ever grows, and it grows at the end, so the page an offset points at
 * drifts under the reader and the deeper the page the more expensive the count that produced it.
 *
 * That is why there is no offset paging here, and why there is no total count: neither can
 * be served cheaply on a table meant to grow forever.
 *
 * The cursor is both halves of the sort key, {@see AuditCursor} — an identifier alone stops being
 * chronological the moment anything is backfilled.
 *
 * @extends ServiceEntityRepository<AuditLog>
 */
final class AuditLogRepository extends ServiceEntityRepository
{
    private const string ALIAS = 'audit_log';

    public function __construct(ManagerRegistry $registry, private readonly AuditTypeResolver $typeResolver)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function createFeedQueryBuilder(?AuditCursor $before = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder(self::ALIAS)
            ->orderBy(self::ALIAS.'.occurredAt', 'DESC')
            ->addOrderBy(self::ALIAS.'.id', 'DESC');

        if (null !== $before) {
            // One lexicographic predicate, not two independent ranges: `occurredAt < :t AND id < :id`
            // would drop every entry of the cursor's own instant that has a smaller identifier, and a
            // single flush stamps all of its entries with one instant.
            $queryBuilder
                ->andWhere(\sprintf(
                    '(%1$s.occurredAt < :before_time OR (%1$s.occurredAt = :before_time AND %1$s.id < :before_id))',
                    self::ALIAS,
                ))
                ->setParameter('before_time', $before->occurredAt, Types::DATETIMETZ_IMMUTABLE)
                ->setParameter('before_id', $before->id, UuidType::NAME);
        }

        return $queryBuilder;
    }

    /**
     * The history of one row, by type or by class — whichever you have at hand.
     *
     * `forEntity(Membership::class, $id)` reads naturally from application code and resolves the
     * declared type for you; `forEntity('membership', $id)` is the same question asked with the type,
     * which is what a URL or a report carries. The query is always on the type: the class name is
     * stored as information and never as a key, so it survives a rename that the class does not.
     */
    public function forEntity(string $entityTypeOrClass, string $entityId, ?AuditCursor $before = null): QueryBuilder
    {
        return $this->createFeedQueryBuilder($before)
            ->andWhere(self::ALIAS.'.entityType = :entity_type')
            ->andWhere(self::ALIAS.'.entityId = :entity_id')
            ->setParameter('entity_type', $this->typeResolver->typeOf($entityTypeOrClass))
            ->setParameter('entity_id', $entityId);
    }

    public function forRoot(string $rootType, string $rootId, ?AuditCursor $before = null): QueryBuilder
    {
        return $this->createFeedQueryBuilder($before)
            ->andWhere(self::ALIAS.'.rootType = :root_type')
            ->andWhere(self::ALIAS.'.rootId = :root_id')
            ->setParameter('root_type', $rootType)
            ->setParameter('root_id', $rootId);
    }

    /**
     * A null type or identifier means "do not constrain that column", not "match rows
     * where it is null" — the trail of an unattributed change is a feed query, not an
     * actor query.
     */
    public function forActor(?string $actorType, ?string $actorId, ?AuditCursor $before = null): QueryBuilder
    {
        $queryBuilder = $this->createFeedQueryBuilder($before);

        if (null !== $actorType) {
            $queryBuilder
                ->andWhere(self::ALIAS.'.actorType = :actor_type')
                ->setParameter('actor_type', $actorType);
        }

        if (null !== $actorId) {
            $queryBuilder
                ->andWhere(self::ALIAS.'.actorId = :actor_id')
                ->setParameter('actor_id', $actorId);
        }

        return $queryBuilder;
    }
}
