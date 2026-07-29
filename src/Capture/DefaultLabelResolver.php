<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Doctrine\Persistence\Proxy;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;

/**
 * Snapshots the human-readable title of an entity: #[AuditLabel], then __toString(), then nothing.
 *
 * "Then nothing" is deliberate. This runs inside the flush, so producing a label must never cost
 * a query: an uninitialised lazy object or an uninitialised typed property yields null rather
 * than a load. A missing label degrades an entry from readable to merely correct — a load in
 * the middle of a flush degrades the whole request.
 *
 * Decorate this service to centralise labelling across a domain (translations, formatted names).
 */
final readonly class DefaultLabelResolver implements LabelResolverInterface
{
    public function __construct(
        private AuditableResolver $auditableResolver,
    ) {
    }

    public function resolve(object $entity): ?string
    {
        if ($this->isUninitialised($entity)) {
            return null;
        }

        $target = $this->auditableResolver->labelTargetFor($entity::class);

        if ($target instanceof \ReflectionProperty) {
            return $target->isInitialized($entity) ? $this->stringify($target->getValue($entity)) : null;
        }

        if ($target instanceof \ReflectionMethod) {
            return $this->stringify($target->invoke($entity));
        }

        return $entity instanceof \Stringable ? $this->stringify((string) $entity) : null;
    }

    private function isUninitialised(object $entity): bool
    {
        if ($entity instanceof Proxy) {
            return !$entity->__isInitialized();
        }

        return (new \ReflectionClass($entity))->isUninitializedLazyObject($entity);
    }

    private function stringify(mixed $value): ?string
    {
        return match (true) {
            null === $value => null,
            \is_string($value) => '' === $value ? null : $value,
            \is_int($value), \is_float($value) => (string) $value,
            $value instanceof \BackedEnum => $this->stringify($value->value),
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \Stringable => $this->stringify((string) $value),
            default => null,
        };
    }
}
