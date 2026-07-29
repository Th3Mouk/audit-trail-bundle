<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Model\Actor;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;

/**
 * Reads persisted rows back as the model they came from.
 *
 * Integration tests then speak the same vocabulary as unit tests: one set of assertions
 * describes capture whether the entries were caught in memory or survived a round trip
 * through the database.
 *
 * Rows are fetched with DQL rather than through a repository so this helper stays usable
 * regardless of what the shipped repository grows into.
 */
final readonly class AuditLogEntries
{
    /**
     * @return list<AuditEntry>
     */
    public static function all(EntityManagerInterface $em): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $em
            ->createQuery(\sprintf('SELECT l FROM %s l ORDER BY l.occurredAt ASC, l.id ASC', AuditLog::class))
            ->getResult();

        return array_map(self::toEntry(...), $logs);
    }

    public static function count(EntityManagerInterface $em): int
    {
        /** @var int|string $count */
        $count = $em
            ->createQuery(\sprintf('SELECT COUNT(l.id) FROM %s l', AuditLog::class))
            ->getSingleScalarResult();

        return (int) $count;
    }

    public static function toEntry(AuditLog $log): AuditEntry
    {
        return new AuditEntry(
            $log->getAction(),
            $log->getEntityType(),
            $log->getEntityId(),
            $log->getOccurredAt(),
            $log->getEntityClass(),
            self::actorOf($log),
            $log->getEntityLabel(),
            $log->getChanges(),
            self::rootOf($log),
            $log->getRequestId(),
            $log->getIp(),
            $log->getMetadata() ?? [],
        );
    }

    private static function actorOf(AuditLog $log): ?Actor
    {
        if (null === $log->getActorId() && null === $log->getActorType() && null === $log->getActorLabel()) {
            return null;
        }

        return new Actor($log->getActorId(), $log->getActorType(), $log->getActorLabel());
    }

    private static function rootOf(AuditLog $log): ?AuditScopeRef
    {
        $type = $log->getRootType();
        $id = $log->getRootId();

        if (null === $type || null === $id) {
            return null;
        }

        return new AuditScopeRef($type, $id, $log->getRootLabel());
    }
}
