<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Capture\ActionResolverInterface;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Capture\ChangeSetSerializer;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Capture\FieldExclusionInterface;
use Th3Mouk\AuditTrail\Capture\LabelResolverInterface;
use Th3Mouk\AuditTrail\Capture\ScopeResolverInterface;
use Th3Mouk\AuditTrail\Context\RequestContext;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;
use Th3Mouk\AuditTrail\Storage\FlushState;

/**
 * Turns a Doctrine flush into audit entries, inside that flush.
 *
 * `onFlush` is the only hook, on purpose. It is the last moment where both sides of a
 * change are still available — an update still has its change set, and a deletion still
 * has a fully hydrated entity, which is what keeps "row 4217 deleted" from being the whole
 * story. It is also early enough for the storage to enlist its rows in the same
 * transaction, so a rolled-back change takes its audit entry with it. Nothing here
 * queries, initialises a lazy association, or flushes.
 *
 * Priority is configuration, not a constant, because this listener has to sit between
 * other people's `onFlush` listeners: *after* those that stamp fields (Timestampable,
 * Blameable) so their values are part of the change set, and *before* those that rewrite
 * change sets (Gedmo Translatable). Register it with the priority from
 * `audit_trail.listener_priority`; do not add `#[AsDoctrineListener]` here, which would
 * hard-code a priority the application cannot move.
 *
 * Running before the rewriters is also why two seams exist for what a scheduled UPDATE
 * really means, and why neither of them names the library doing the rewriting:
 * `ActionResolverInterface` reclassifies the update itself — a logical delete is a
 * deletion — and `FieldExclusionInterface` drops the fields whose change is about to be
 * diverted somewhere other than the entity's own columns.
 *
 * Collection and many-to-many deltas are out of scope: `getScheduledCollectionUpdates()`
 * is deliberately not read. Audit the owning child entity instead — a pivot row deletion
 * is a fact with an identifier, a collection diff is not.
 */
final readonly class AuditLogListener
{
    public function __construct(
        private AuditableResolver $auditableResolver,
        private CaptureGateInterface $captureGate,
        private ActorResolverInterface $actorResolver,
        private LabelResolverInterface $labelResolver,
        private ScopeResolverInterface $scopeResolver,
        private EntityIdResolver $entityIdResolver,
        private ChangeSetSerializer $changeSetSerializer,
        private ActionResolverInterface $actionResolver,
        private FieldExclusionInterface $fieldExclusion,
        private AuditStorageInterface $storage,
        private FlushState $flushState,
        private RequestContext $requestContext,
        private bool $enabled = true,
        private bool $stateOnCreate = true,
        private bool $stateOnDelete = true,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $occurredAt = new \DateTimeImmutable();

        $this->flushState->enterFlush();

        try {
            $entries = [
                ...$this->collectCreations($entityManager, $unitOfWork, $occurredAt),
                ...$this->collectUpdates($entityManager, $unitOfWork, $occurredAt),
                ...$this->collectDeletions($entityManager, $unitOfWork, $occurredAt),
            ];

            if ([] === $entries) {
                return;
            }

            $actor = $this->actorResolver->resolve();

            foreach ($entries as $entry) {
                $this->storage->store($entry->withActor($actor));
            }
        } finally {
            $this->flushState->leaveFlush();
        }
    }

    /**
     * @return list<AuditEntry>
     */
    private function collectCreations(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        \DateTimeImmutable $occurredAt,
    ): array {
        $entries = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $entityClass = $this->capturableClass($entityManager, $entity, AuditAction::Create);

            if (null === $entityClass) {
                continue;
            }

            $entries[] = $this->buildEntry(
                $entityClass,
                $entity,
                AuditAction::Create,
                $occurredAt,
                $this->stateOnCreate
                    ? $this->changeSetSerializer->serializeState($entity, $this->fieldValuesOf($entityManager, $entity))
                    : null,
            );
        }

        return $entries;
    }

    /**
     * A scheduled UPDATE is the only ambiguous case, so it is the only one that asks.
     *
     * The action is resolved from the *whole* change set — what happened is not a function of
     * what gets recorded — and the excluded fields are then taken out of the payload alone. A
     * reclassified deletion carries the entity's state, exactly like one Doctrine scheduled
     * itself, because a diff on a date column is not a legible account of a deletion.
     *
     * @return list<AuditEntry>
     */
    private function collectUpdates(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        \DateTimeImmutable $occurredAt,
    ): array {
        $entries = [];

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $entityClass = $this->auditableClass($entityManager, $entity);

            if (null === $entityClass) {
                continue;
            }

            $changeSet = $this->fieldChangesOf($unitOfWork, $entity);
            $action = $this->actionResolver->resolveAction($entity, $changeSet) ?? AuditAction::Update;

            if (!$this->captureGate->shouldCapture($entity, $action)) {
                continue;
            }

            if (AuditAction::Delete === $action) {
                $entries[] = $this->buildEntry(
                    $entityClass,
                    $entity,
                    $action,
                    $occurredAt,
                    $this->stateAtDeletionOf($entityManager, $entity),
                );

                continue;
            }

            $changes = $this->changeSetSerializer->serializeChangeSet(
                $entity,
                $this->withoutExcludedFields($entityManager, $entity, $changeSet),
            );

            if (null === $changes) {
                continue;
            }

            $entries[] = $this->buildEntry($entityClass, $entity, $action, $occurredAt, $changes);
        }

        return $entries;
    }

    /**
     * @return list<AuditEntry>
     */
    private function collectDeletions(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        \DateTimeImmutable $occurredAt,
    ): array {
        $entries = [];

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $entityClass = $this->capturableClass($entityManager, $entity, AuditAction::Delete);

            if (null === $entityClass) {
                continue;
            }

            $entries[] = $this->buildEntry(
                $entityClass,
                $entity,
                AuditAction::Delete,
                $occurredAt,
                $this->stateAtDeletionOf($entityManager, $entity),
            );
        }

        return $entries;
    }

    /**
     * Shared by both roads to a `Delete` entry — the one Doctrine scheduled and the one an
     * `ActionResolverInterface` reclassified — because they must be indistinguishable to a reader
     * and both answer to `capture.state_on_delete`.
     *
     * @return array<string, mixed>|null
     */
    private function stateAtDeletionOf(EntityManagerInterface $entityManager, object $entity): ?array
    {
        if (!$this->stateOnDelete) {
            return null;
        }

        return $this->changeSetSerializer->serializeState($entity, $this->fieldValuesOf($entityManager, $entity));
    }

    /**
     * @return class-string|null
     */
    private function capturableClass(
        EntityManagerInterface $entityManager,
        object $entity,
        AuditAction $action,
    ): ?string {
        $entityClass = $this->auditableClass($entityManager, $entity);

        if (null === $entityClass) {
            return null;
        }

        if (!$this->captureGate->shouldCapture($entity, $action)) {
            return null;
        }

        return $entityClass;
    }

    /**
     * Split out from `capturableClass()` because an update has to know its real action before
     * the gates can be asked about it, and the action is read from the change set.
     *
     * @return class-string|null
     */
    private function auditableClass(EntityManagerInterface $entityManager, object $entity): ?string
    {
        $entityClass = $entityManager->getClassMetadata($entity::class)->getName();

        return $this->auditableResolver->isAuditable($entityClass) ? $entityClass : null;
    }

    /**
     * @param array<string, list<mixed>> $changeSet
     *
     * @return array<string, list<mixed>>
     */
    private function withoutExcludedFields(
        EntityManagerInterface $entityManager,
        object $entity,
        array $changeSet,
    ): array {
        $excluded = $this->fieldExclusion->excludedFields($entityManager, $entity);

        if ([] === $excluded) {
            return $changeSet;
        }

        return array_diff_key($changeSet, array_fill_keys($excluded, true));
    }

    /**
     * @param array<string, mixed>|null $changes
     */
    private function buildEntry(
        string $entityClass,
        object $entity,
        AuditAction $action,
        \DateTimeImmutable $occurredAt,
        ?array $changes,
    ): AuditEntry {
        return new AuditEntry(
            $action,
            $entityClass,
            $this->entityIdResolver->resolve($entity) ?? '',
            $occurredAt,
            entityLabel: $this->labelResolver->resolve($entity),
            changes: $changes,
            root: $this->resolveRoot($entity),
            requestId: $this->requestContext->getRequestId(),
            ip: $this->requestContext->getClientIp(),
        );
    }

    /**
     * Doctrine reports collection changes alongside field changes. Collection deltas are
     * deliberately out of scope, so they never reach the serializer.
     *
     * @return array<string, list<mixed>>
     */
    private function fieldChangesOf(UnitOfWork $unitOfWork, object $entity): array
    {
        $fieldChanges = [];

        foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $change) {
            if (\is_array($change)) {
                $fieldChanges[$field] = array_values($change);
            }
        }

        return $fieldChanges;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldValuesOf(EntityManagerInterface $entityManager, object $entity): array
    {
        $metadata = $entityManager->getClassMetadata($entity::class);
        $fields = $metadata->getFieldNames();

        foreach (array_keys($metadata->getAssociationMappings()) as $association) {
            if ($metadata->isSingleValuedAssociation($association)) {
                $fields[] = $association;
            }
        }

        $values = [];

        foreach ($fields as $field) {
            try {
                $values[$field] = $metadata->getFieldValue($entity, $field);
            } catch (\Error) {
                continue;
            }
        }

        return $values;
    }

    private function resolveRoot(object $entity): ?AuditScopeRef
    {
        $root = $this->scopeResolver->resolve($entity);

        if (null === $root) {
            return null;
        }

        return AuditScopeRef::of(
            $this->scopeResolver->resolveType($entity) ?? $root->class,
            $root->id,
            $root->label,
        );
    }
}
