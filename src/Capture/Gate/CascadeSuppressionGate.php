<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Gate;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * Keeps a cascade from flooding the trail.
 *
 * Deleting one aggregate can schedule dozens of children in the same flush. Recording
 * each of them separately buries the fact that actually happened — "the questionnaire was
 * deleted" — under its own consequences. When an entity scheduled for deletion has an
 * owning object that is *also* being deleted in this flush, the child entry is dropped;
 * the root's own entry, which carries its full state at delete, remains.
 *
 * The current UnitOfWork comes from the EntityManager rather than from a setter driven by
 * the listener: a gate is consulted mid-flush, so `$entityManager->getUnitOfWork()` is
 * already the very unit of work being flushed. That keeps the gate usable on its own
 * (and unit-testable) instead of only being correct when some other service remembered
 * to hand it a context.
 *
 * The walk reads already-loaded values only — an uninitialised owner is left alone rather
 * than lazy-loaded, because capture must never issue a query.
 */
final readonly class CascadeSuppressionGate implements CaptureGateInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private bool $suppressCascadeChildren = true,
    ) {
    }

    public function shouldCapture(object $entity, AuditAction $action): bool
    {
        if (!$this->suppressCascadeChildren || AuditAction::Delete !== $action) {
            return true;
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $visited = [spl_object_id($entity) => true];

        return !$this->hasOwnerScheduledForDeletion($entity, $unitOfWork, $visited);
    }

    /**
     * @param array<int, true> $visited
     */
    private function hasOwnerScheduledForDeletion(object $entity, UnitOfWork $unitOfWork, array &$visited): bool
    {
        if ($unitOfWork->isUninitializedObject($entity)) {
            return false;
        }

        $metadata = $this->entityManager->getClassMetadata($entity::class);

        foreach ($this->owningSideCandidates($metadata) as $fieldName) {
            $owner = $metadata->getFieldValue($entity, $fieldName);

            if (!\is_object($owner)) {
                continue;
            }

            $ownerReference = spl_object_id($owner);

            if (isset($visited[$ownerReference])) {
                continue;
            }

            $visited[$ownerReference] = true;

            if ($unitOfWork->isScheduledForDelete($owner)) {
                return true;
            }

            if ($this->hasOwnerScheduledForDeletion($owner, $unitOfWork, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private function owningSideCandidates(ClassMetadata $metadata): array
    {
        $fieldNames = [];

        foreach ($metadata->associationMappings as $association) {
            if ($association->isToOne()) {
                $fieldNames[] = $association->fieldName;
            }
        }

        return $fieldNames;
    }
}
