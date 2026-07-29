<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Doctrine\Persistence\Proxy;
use Th3Mouk\AuditTrail\Attribute\AuditScope;
use Th3Mouk\AuditTrail\Exception\InvalidAuditScope;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Scope\AuditScopeProviderInterface;

/**
 * Resolves the aggregate an entry belongs to, so a per-aggregate history is one indexed read.
 *
 * Two sources, in order: the entity's own {@see AuditScopeProviderInterface} when the root is
 * conditional or polymorphic, otherwise the dotted getter path of {@see AuditScope}.
 *
 * The walk is memory-only. A hop that is null, uninitialised or lazily unloaded ends the walk
 * with null — an entry without a root is still a valid entry, whereas a query inside `onFlush`
 * is a bug. A hop that does not exist at all is a mapping mistake and throws.
 */
final readonly class DefaultScopeResolver implements ScopeResolverInterface
{
    public function __construct(
        private AuditableResolver $auditableResolver,
        private EntityIdResolver $entityIdResolver,
        private LabelResolverInterface $labelResolver,
    ) {
    }

    public function resolve(object $entity): ?AuditRef
    {
        if ($entity instanceof AuditScopeProviderInterface) {
            return $entity->resolveAuditRoot();
        }

        $scope = $this->auditableResolver->scopeFor($entity::class);

        if (null === $scope) {
            return null;
        }

        $root = $this->walk($entity, $scope);

        if (null === $root) {
            return null;
        }

        if (!$root instanceof $scope->root) {
            throw InvalidAuditScope::rootMismatch($entity::class, $scope->root, $this->classOf($root));
        }

        $id = $this->entityIdResolver->resolve($root);

        if (null === $id) {
            return null;
        }

        return AuditRef::of($this->classOf($root), $id, $this->labelResolver->resolve($root));
    }

    public function resolveType(object $entity): ?string
    {
        if ($entity instanceof AuditScopeProviderInterface) {
            $root = $entity->resolveAuditRoot();

            return null === $root ? null : $this->discriminatorOf($root->class);
        }

        $scope = $this->auditableResolver->scopeFor($entity::class);

        if (null === $scope) {
            return null;
        }

        return $scope->type ?? $this->discriminatorOf($scope->root);
    }

    private function walk(object $entity, AuditScope $scope): ?object
    {
        $segments = array_values(array_filter(
            explode('.', $scope->via),
            static fn (string $segment): bool => '' !== trim($segment),
        ));

        if ([] === $segments) {
            throw InvalidAuditScope::emptyPath($entity::class);
        }

        $current = $entity;

        foreach ($segments as $segment) {
            if ($this->isUninitialised($current)) {
                return null;
            }

            $accessor = $this->accessorFor($current, $segment)
                ?? throw InvalidAuditScope::unreachableSegment($entity::class, $scope->via, $segment);

            if (!$this->isReadable($current, $segment)) {
                return null;
            }

            $next = $accessor();

            if (!\is_object($next)) {
                return null;
            }

            $current = $next;
        }

        return $current;
    }

    /**
     * @return (\Closure(): mixed)|null
     */
    private function accessorFor(object $current, string $segment): ?\Closure
    {
        $capitalised = ucfirst($segment);

        foreach (['get'.$capitalised, 'is'.$capitalised, $segment] as $candidate) {
            if ($this->isCallableGetter($current, $candidate)) {
                return static fn (): mixed => $current->{$candidate}();
            }
        }

        $reflection = new \ReflectionObject($current);

        if ($reflection->hasProperty($segment) && $reflection->getProperty($segment)->isPublic()) {
            return static fn (): mixed => $current->{$segment};
        }

        return null;
    }

    private function isCallableGetter(object $current, string $method): bool
    {
        if (!method_exists($current, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($current, $method);

        return $reflection->isPublic()
            && !$reflection->isStatic()
            && 0 === $reflection->getNumberOfRequiredParameters();
    }

    private function isReadable(object $current, string $segment): bool
    {
        for ($level = new \ReflectionClass($current); false !== $level; $level = $level->getParentClass()) {
            if (!$level->hasProperty($segment)) {
                continue;
            }

            return $level->getProperty($segment)->isInitialized($current);
        }

        return true;
    }

    private function isUninitialised(object $candidate): bool
    {
        if ($candidate instanceof Proxy) {
            return !$candidate->__isInitialized();
        }

        return (new \ReflectionClass($candidate))->isUninitializedLazyObject($candidate);
    }

    /**
     * @return class-string
     */
    private function classOf(object $candidate): string
    {
        if (!$candidate instanceof Proxy) {
            return $candidate::class;
        }

        $parent = get_parent_class($candidate);

        return false === $parent ? $candidate::class : $parent;
    }

    private function discriminatorOf(string $class): string
    {
        $shortName = substr(strrchr('\\'.$class, '\\') ?: '', 1);

        return strtolower((string) preg_replace(
            ['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'],
            '$1_$2',
            $shortName,
        ));
    }
}
