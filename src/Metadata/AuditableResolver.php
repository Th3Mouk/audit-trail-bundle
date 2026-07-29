<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;
use Th3Mouk\AuditTrail\Attribute\AuditScope;
use Th3Mouk\AuditTrail\Exception\AuditableEntityNotSupported;

/**
 * Answers "is this class audited, where does it belong, and what is it called" — once per class.
 *
 * The three questions share a resolver because they share a walk: attributes are read from the
 * class and then up its parents, the nearest declaration winning. That is what makes
 * `#[Auditable]` inheritable while still letting a child opt back out with `enabled: false`.
 *
 * Every answer is memoised, because this runs inside `onFlush` where reflection cost is paid
 * per entity, not per request.
 */
final class AuditableResolver
{
    /** @var array<class-string, bool> */
    private array $auditable = [];

    /** @var array<class-string, AuditScope|null> */
    private array $scopes = [];

    /** @var array<class-string, \ReflectionProperty|\ReflectionMethod|null> */
    private array $labelTargets = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param class-string $class
     */
    public function isAuditable(string $class): bool
    {
        if (\array_key_exists($class, $this->auditable)) {
            return $this->auditable[$class];
        }

        $enabled = $this->readAuditableFlag($class);

        if ($enabled) {
            $this->assertSingleIdentifier($class);
        }

        return $this->auditable[$class] = $enabled;
    }

    /**
     * @param class-string $class
     */
    public function scopeFor(string $class): ?AuditScope
    {
        return $this->scopes[$class] ??= $this->readScope($class);
    }

    /**
     * The member designated by #[AuditLabel], or null when the class designates none.
     *
     * @param class-string $class
     */
    public function labelTargetFor(string $class): \ReflectionProperty|\ReflectionMethod|null
    {
        if (\array_key_exists($class, $this->labelTargets)) {
            return $this->labelTargets[$class];
        }

        return $this->labelTargets[$class] = $this->readLabelTarget($class);
    }

    /**
     * @param class-string $class
     */
    private function readAuditableFlag(string $class): bool
    {
        foreach ($this->hierarchyOf($class) as $level) {
            $attributes = $level->getAttributes(Auditable::class);

            if ([] !== $attributes) {
                return $attributes[0]->newInstance()->enabled;
            }
        }

        return false;
    }

    /**
     * @param class-string $class
     */
    private function readScope(string $class): ?AuditScope
    {
        foreach ($this->hierarchyOf($class) as $level) {
            $attributes = $level->getAttributes(AuditScope::class);

            if ([] !== $attributes) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }

    /**
     * @param class-string $class
     */
    private function readLabelTarget(string $class): \ReflectionProperty|\ReflectionMethod|null
    {
        foreach ($this->hierarchyOf($class) as $level) {
            foreach ($level->getProperties() as $property) {
                if ($property->getDeclaringClass()->name !== $level->name) {
                    continue;
                }

                if ([] !== $property->getAttributes(AuditLabel::class)) {
                    return $property;
                }
            }

            foreach ($level->getMethods() as $method) {
                if ($method->getDeclaringClass()->name !== $level->name) {
                    continue;
                }

                if ($method->isStatic() || 0 !== $method->getNumberOfRequiredParameters()) {
                    continue;
                }

                if ([] !== $method->getAttributes(AuditLabel::class)) {
                    return $method;
                }
            }
        }

        return null;
    }

    /**
     * @param class-string $class
     *
     * @return list<\ReflectionClass<object>>
     */
    private function hierarchyOf(string $class): array
    {
        $levels = [];

        for ($level = new \ReflectionClass($class); false !== $level; $level = $level->getParentClass()) {
            $levels[] = $level;
        }

        return $levels;
    }

    /**
     * @param class-string $class
     */
    private function assertSingleIdentifier(string $class): void
    {
        if ($this->entityManager->getMetadataFactory()->isTransient($class)) {
            return;
        }

        if ($this->entityManager->getClassMetadata($class)->isIdentifierComposite) {
            throw AuditableEntityNotSupported::compositeIdentifier($class);
        }
    }
}
