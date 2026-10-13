<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Proxy;

/**
 * Reads an entity's identifier without touching the database.
 *
 * Inside a flush the identifier is often already known to the UnitOfWork even though the
 * property has not been written back yet, so that map is consulted first. When it is not —
 * a brand-new entity waiting on a sequence — the honest answer is null: the caller decides
 * whether to defer or to drop the reference, and neither is worth a query.
 *
 * A brand-new entity whose key the database assigns has no identifier *whatever its property
 * holds*. Doctrine ignores that property on insert, and `clone` copies it from the source: reading
 * it would hand a clone the key of the row it was copied from, and file the clone's history under
 * the original's.
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

        if ($this->isPendingFor($metadata, $entity)) {
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
     * Whether the entity is waiting on its INSERT to be given a key, and so has none yet.
     *
     * True from the moment it is scheduled for insertion until the INSERT has run; from then on the
     * key is in the identity map and {@see resolve()} reads it there. An entity keyed before its
     * INSERT — assigned by the application, a UUID, a sequence — is never pending.
     */
    public function isPending(object $entity): bool
    {
        $class = $this->classOf($entity);

        if ($this->entityManager->getMetadataFactory()->isTransient($class)) {
            return false;
        }

        return $this->isPendingFor($this->entityManager->getClassMetadata($class), $entity);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function isPendingFor(ClassMetadata $metadata, object $entity): bool
    {
        return $metadata->idGenerator->isPostInsertGenerator()
            && $this->entityManager->getUnitOfWork()->isScheduledForInsert($entity);
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
