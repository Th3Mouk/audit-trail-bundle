<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;

/**
 * Reads an entity's identifier without touching the database.
 *
 * Inside a flush the identifier is often already known to the UnitOfWork even though the
 * property has not been written back yet, so that map is consulted first. When it is not —
 * a brand-new entity waiting on a sequence — the honest answer is null: the caller decides
 * whether to defer or to drop the reference, and neither is worth a query.
 */
final readonly class EntityIdResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(object $entity): ?string
    {
        $class = $this->classOf($entity);

        if ($this->entityManager->getMetadataFactory()->isTransient($class)) {
            return $this->fromReflection($entity);
        }

        $metadata = $this->entityManager->getClassMetadata($class);

        if ($metadata->isIdentifierComposite || [] === $metadata->identifier) {
            return null;
        }

        $field = $metadata->identifier[0];
        $unitOfWork = $this->entityManager->getUnitOfWork();

        if ($unitOfWork->isInIdentityMap($entity)) {
            return $this->stringify($unitOfWork->getEntityIdentifier($entity)[$field] ?? null);
        }

        if ($unitOfWork->isUninitializedObject($entity)) {
            return null;
        }

        return $this->stringify($metadata->getIdentifierValues($entity)[$field] ?? null);
    }

    /**
     * @return class-string
     */
    private function classOf(object $entity): string
    {
        if (!$entity instanceof Proxy) {
            return $entity::class;
        }

        $parent = get_parent_class($entity);

        return false === $parent ? $entity::class : $parent;
    }

    private function fromReflection(object $entity): ?string
    {
        for ($level = new \ReflectionClass($entity); false !== $level; $level = $level->getParentClass()) {
            if (!$level->hasProperty('id')) {
                continue;
            }

            $property = $level->getProperty('id');

            return $property->isInitialized($entity) ? $this->stringify($property->getValue($entity)) : null;
        }

        return null;
    }

    private function stringify(mixed $value): ?string
    {
        return match (true) {
            null === $value => null,
            \is_int($value) => (string) $value,
            \is_string($value) => '' === $value ? null : $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \Stringable => $this->stringify((string) $value),
            default => null,
        };
    }
}
