<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Value;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Capture\LabelResolverInterface;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;
use Th3Mouk\AuditTrail\Model\AuditRef;

/**
 * Captures an association as class + id + a snapshot of its label.
 *
 * The label is what makes the entry survive the referenced row: "manager Jean Dupont removed"
 * stays legible after both the grant and the user are gone, which no foreign key can promise.
 *
 * Whether a value is an entity is asked of Doctrine's metadata, never inferred from the shape
 * of the object, so a value object that happens to own an `$id` is not mistaken for a row.
 * An uninitialised association is captured with a null label rather than loaded.
 */
final readonly class EntityReferenceValueSerializer implements ValueSerializerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntityIdResolver $entityIdResolver,
        private LabelResolverInterface $labelResolver,
    ) {
    }

    public function supports(mixed $value): bool
    {
        return \is_object($value)
            && !$this->entityManager->getMetadataFactory()->isTransient($this->classOf($value));
    }

    public function serialize(mixed $value): mixed
    {
        if (!\is_object($value)) {
            return null;
        }

        $id = $this->entityIdResolver->resolve($value);

        if (null === $id) {
            return null;
        }

        return AuditRef::of($this->classOf($value), $id, $this->labelResolver->resolve($value));
    }

    /**
     * @return class-string
     */
    private function classOf(object $value): string
    {
        if (!$value instanceof Proxy) {
            return $value::class;
        }

        $parent = get_parent_class($value);

        return false === $parent ? $value::class : $parent;
    }
}
