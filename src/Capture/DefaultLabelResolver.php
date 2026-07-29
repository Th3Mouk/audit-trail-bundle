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
            return $this->call(static fn (): mixed => $target->invoke($entity));
        }

        return $entity instanceof \Stringable
            ? $this->call(static fn (): string => (string) $entity)
            : null;
    }

    /**
     * Runs application code that is expected to be memory-only, and survives it if it is not.
     *
     * A `#[AuditLabel]` method and `__toString()` are the one place where capture calls back into the
     * host, and it happens inside the flush. The contract — documented on the attribute — is that such
     * a method reads its own already-loaded state and nothing else: touching an uninitialised
     * association issues a query mid-flush, which is precisely what the rest of the pipeline is built
     * to avoid. The bundle cannot enforce that, so it does the next best thing: a label that throws
     * costs a label, never the write. Losing a snapshot is cosmetic; breaking someone's flush because
     * their `__toString()` reached for a detached object is not.
     *
     * @param \Closure(): mixed $label
     */
    private function call(\Closure $label): ?string
    {
        try {
            return $this->stringify($label());
        } catch (\Throwable) {
            return null;
        }
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
