<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\Gedmo;

use Gedmo\Mapping\Annotation\SoftDeleteable;
use Th3Mouk\AuditTrail\Capture\ActionResolverInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * Tells a logical delete apart from an ordinary update.
 *
 * Gedmo's SoftDeleteableListener cancels the removal and turns it into an UPDATE of the
 * configured date field, so an audit trail that only reads the change set records
 * "update: deletedAt null → 2026-07-29" — technically true, useless to a human, and it
 * loses the one fact that mattered. Applications that soft delete by hand
 * (`$entity->setDeletedAt(new \DateTimeImmutable())`) produce the same change set without
 * Gedmo being involved at all, and are corrected here too — that case is the reason this
 * class exists, because no listener ordering reaches it.
 *
 * The decision is taken from the change set alone: no property is read, no association is
 * initialised, no query is issued, so this is safe to call from `onFlush`.
 *
 * Reaches the capture listener through `ActionResolverInterface`, registered from
 * `bridge_gedmo.php` under the `audit_trail.action_resolver` tag. Nothing in the core has to
 * know that Gedmo exists, and an application can still call the class directly.
 */
final class SoftDeleteableActionResolver implements ActionResolverInterface
{
    public const string TRANSITION_DELETE = 'delete';

    public const string TRANSITION_RESTORE = 'restore';

    /**
     * @var array<class-string, string|null>
     */
    private array $deletedAtFields = [];

    /**
     * Delete for a logical delete, Update for a restore, null when the entity is not
     * soft deleteable or its date field did not change — leaving the caller's own
     * action untouched.
     *
     * @param array<string, array<int, mixed>> $changeSet
     */
    public function resolveAction(object $entity, array $changeSet): ?AuditAction
    {
        return match ($this->resolveTransition($entity, $changeSet)) {
            self::TRANSITION_DELETE => AuditAction::Delete,
            self::TRANSITION_RESTORE => AuditAction::Update,
            default => null,
        };
    }

    /**
     * @param array<string, array<int, mixed>> $changeSet
     *
     * @return self::TRANSITION_DELETE|self::TRANSITION_RESTORE|null
     */
    public function resolveTransition(object $entity, array $changeSet): ?string
    {
        $field = $this->deletedAtField($entity::class);

        if (null === $field || !\array_key_exists($field, $changeSet)) {
            return null;
        }

        $before = $changeSet[$field][0] ?? null;
        $after = $changeSet[$field][1] ?? null;

        if (null === $before && null !== $after) {
            return self::TRANSITION_DELETE;
        }

        if (null !== $before && null === $after) {
            return self::TRANSITION_RESTORE;
        }

        return null;
    }

    /**
     * @param array<string, array<int, mixed>> $changeSet
     */
    public function isLogicalDelete(object $entity, array $changeSet): bool
    {
        return self::TRANSITION_DELETE === $this->resolveTransition($entity, $changeSet);
    }

    /**
     * @param array<string, array<int, mixed>> $changeSet
     */
    public function isRestore(object $entity, array $changeSet): bool
    {
        return self::TRANSITION_RESTORE === $this->resolveTransition($entity, $changeSet);
    }

    /**
     * @param class-string $entityClass
     */
    public function isSoftDeleteable(string $entityClass): bool
    {
        return null !== $this->deletedAtField($entityClass);
    }

    /**
     * The property Gedmo stamps on a logical delete, read from the entity's own
     * `#[SoftDeleteable]` attribute or the nearest annotated ancestor.
     *
     * @param class-string $entityClass
     */
    public function deletedAtField(string $entityClass): ?string
    {
        if (\array_key_exists($entityClass, $this->deletedAtFields)) {
            return $this->deletedAtFields[$entityClass];
        }

        return $this->deletedAtFields[$entityClass] = $this->readConfiguredField($entityClass);
    }

    /**
     * @param class-string $entityClass
     */
    private function readConfiguredField(string $entityClass): ?string
    {
        if (!class_exists(SoftDeleteable::class) || !class_exists($entityClass)) {
            return null;
        }

        for ($reflection = new \ReflectionClass($entityClass); false !== $reflection; $reflection = $reflection->getParentClass()) {
            $attributes = $reflection->getAttributes(SoftDeleteable::class);

            if ([] === $attributes) {
                continue;
            }

            $fieldName = $attributes[0]->newInstance()->fieldName;

            return '' !== $fieldName ? $fieldName : null;
        }

        return null;
    }
}
