<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Storage;

use Doctrine\ORM\EntityManagerInterface;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Model\AuditEntry;

/**
 * Writes the trail in the same transaction as the change it describes.
 *
 * Called from the flush that produced the change, the row has to join a unit of work
 * Doctrine has already made its mind up about, which takes an explicit change-set
 * computation. Called from application code instead — the manual logger, an import — there
 * is no flush to join: the row is merely persisted and the caller's own flush writes it.
 *
 * Getting that distinction wrong is not a missing row, it is a wrong one: the insert
 * persister prepares one statement per class and binds its parameters from the change set
 * alone, so a row that joins a flush without one is executed with the parameters the
 * previous row left bound — the same primary key, twice. Hence `FlushState`, and hence the
 * duty of every listener that records inside a flush to say so.
 *
 * This service never flushes. Flushing from inside a flush is how Doctrine listeners
 * produce corrupted units of work, and flushing from outside one would decide on the
 * caller's behalf when their transaction ends.
 */
final readonly class DoctrineAuditStorage implements AuditStorageInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FlushState $flushState,
    ) {
    }

    public function store(AuditEntry $entry): void
    {
        $log = AuditLog::fromEntry($entry);

        $this->entityManager->persist($log);

        if (!$this->flushState->isFlushing()) {
            return;
        }

        $this->entityManager->getUnitOfWork()->computeChangeSet(
            $this->entityManager->getClassMetadata(AuditLog::class),
            $log,
        );
    }
}
