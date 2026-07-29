<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Fields whose presence in an UPDATE change set does not mean the entity's own column changes.
 *
 * Capture has to read change sets before other `onFlush` listeners rewrite them, which means it
 * can see a value that is about to be taken back out again. Reporting it would put a column
 * change in the trail that never reaches the table — a wrong entry, not a missing one. The
 * fields concerned are known to whoever does the rewriting, so that knowledge is contributed
 * here rather than guessed at by the capture listener.
 *
 * Applies to updates only. An insertion writes the entity's own columns whatever happens
 * afterwards, and a deletion has no change set to trim.
 *
 * Consulted inside `onFlush`: no query, no association initialised. Return an empty list for
 * anything you do not recognise.
 *
 * Register an implementation with the `audit_trail.field_exclusion` tag. Every contributor is
 * consulted and the results are merged, so an exclusion can only ever remove a field from an
 * entry, never add one.
 */
interface FieldExclusionInterface
{
    /**
     * @return list<string> field names, as Doctrine names them in the change set
     */
    public function excludedFields(EntityManagerInterface $entityManager, object $entity): array;
}
