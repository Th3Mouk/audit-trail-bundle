<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;

/**
 * Turns a Doctrine change set into the JSON payload of one entry — or into nothing at all.
 *
 * Two rules carry the whole design.
 *
 * The row-trigger rule: ignored properties are stripped *before* deciding whether anything
 * happened, so a flush that only bumps a technical column writes no row, while a flush that
 * only touches a masked column writes one. Emptiness of the payload is therefore not the same
 * question as emptiness of the change — hence the null return rather than an empty array.
 *
 * The masking rule: a masked value is never read. The sentinel is emitted from the mere
 * presence of the key in the change set, so a secret cannot leak through this class even by
 * accident.
 */
final readonly class ChangeSetSerializer
{
    public function __construct(
        private FieldPolicyResolver $fieldPolicyResolver,
        private ValueSerializerInterface $valueSerializer,
    ) {
    }

    /**
     * @param array<string, array<int, mixed>> $changeSet
     *
     * @return array<string, array{before: mixed, after: mixed}>|null null when nothing should be recorded
     */
    public function serializeChangeSet(object $entity, array $changeSet): ?array
    {
        $class = $entity::class;
        $changes = [];
        $rowTriggering = false;

        foreach (array_keys($changeSet) as $field) {
            $policy = $this->fieldPolicyResolver->policyFor($class, $field);

            if (!$policy->isRowTriggering()) {
                continue;
            }

            $rowTriggering = true;

            if (!$policy->recordsValue()) {
                $mask = $this->fieldPolicyResolver->maskFor($class, $field);
                $changes[$field] = ['before' => $mask, 'after' => $mask];

                continue;
            }

            $before = $changeSet[$field][0] ?? null;
            $after = $changeSet[$field][1] ?? null;

            if (!$this->valueSerializer->supports($before) || !$this->valueSerializer->supports($after)) {
                continue;
            }

            $changes[$field] = [
                'before' => $this->valueSerializer->serialize($before),
                'after' => $this->valueSerializer->serialize($after),
            ];
        }

        return $rowTriggering ? $changes : null;
    }

    /**
     * The full state of an entity, for the create and delete entries that must stand alone.
     *
     * @param array<string, mixed> $fieldValues
     *
     * @return array<string, mixed>
     */
    public function serializeState(object $entity, array $fieldValues): array
    {
        $class = $entity::class;
        $state = [];

        foreach (array_keys($fieldValues) as $field) {
            $policy = $this->fieldPolicyResolver->policyFor($class, $field);

            if (!$policy->isRowTriggering()) {
                continue;
            }

            if (!$policy->recordsValue()) {
                $state[$field] = $this->fieldPolicyResolver->maskFor($class, $field);

                continue;
            }

            $value = $fieldValues[$field];

            if (!$this->valueSerializer->supports($value)) {
                continue;
            }

            $state[$field] = $this->valueSerializer->serialize($value);
        }

        return $state;
    }
}
