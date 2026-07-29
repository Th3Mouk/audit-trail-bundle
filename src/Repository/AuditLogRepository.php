<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Th3Mouk\AuditTrail\Entity\AuditLog;

/**
 * Reading the trail, one page at a time.
 *
 * Every helper returns a builder ordered by identifier descending. Because the identifier
 * is a UUID v7 it is also a chronological cursor, so paging is done by asking for entries
 * before a known one instead of counting rows to skip. A trail only ever grows, and it
 * grows at the end — the page an offset points at drifts under the reader, and the deeper
 * the page the more expensive the count that produced it.
 *
 * That is why there is no offset paging here, and why there is no total count: neither can
 * be served cheaply on a table meant to grow forever.
 *
 * @extends ServiceEntityRepository<AuditLog>
 */
final class AuditLogRepository extends ServiceEntityRepository
{
    private const string ALIAS = 'audit_log';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function createFeedQueryBuilder(?Uuid $beforeCursor = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder(self::ALIAS)
            ->orderBy(self::ALIAS.'.id', 'DESC');

        if (null !== $beforeCursor) {
            $queryBuilder
                ->andWhere(self::ALIAS.'.id < :before_cursor')
                ->setParameter('before_cursor', $beforeCursor, UuidType::NAME);
        }

        return $queryBuilder;
    }

    public function forEntity(string $entityClass, string $entityId, ?Uuid $beforeCursor = null): QueryBuilder
    {
        return $this->createFeedQueryBuilder($beforeCursor)
            ->andWhere(self::ALIAS.'.entityClass = :entity_class')
            ->andWhere(self::ALIAS.'.entityId = :entity_id')
            ->setParameter('entity_class', $entityClass)
            ->setParameter('entity_id', $entityId);
    }

    public function forRoot(string $rootType, string $rootId, ?Uuid $beforeCursor = null): QueryBuilder
    {
        return $this->createFeedQueryBuilder($beforeCursor)
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
    public function forActor(?string $actorType, ?string $actorId, ?Uuid $beforeCursor = null): QueryBuilder
    {
        $queryBuilder = $this->createFeedQueryBuilder($beforeCursor);

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
