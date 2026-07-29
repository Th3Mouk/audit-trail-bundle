<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\MappingException;
use Th3Mouk\AuditTrail\Attribute\AuditMasked;
use Th3Mouk\AuditTrail\Attribute\NotAuditable;
use Th3Mouk\AuditTrail\Exception\ConflictingFieldPolicy;

/**
 * Decides, per property, whether a change is recorded, masked or dropped.
 *
 * Properties are tracked by default: opting a field out is a deliberate act, so a new column
 * is audited the day it is added rather than the day someone remembers to list it.
 *
 * The lookup walks parent classes so that a private property declared on a base class keeps
 * its attributes, and memoises per class+property because it is called for every changed
 * field of every flushed entity.
 */
final class FieldPolicyResolver
{
    /** @var array<string, array{policy: FieldPolicy, mask: string}> */
    private array $resolved = [];

    /**
     * The entity manager is optional and only ever makes an answer *more* precise: without it,
     * embedded paths are resolved by reflection alone, and whatever cannot be resolved is masked
     * rather than recorded. No configuration can turn this into a leak.
     */
    public function __construct(
        private readonly string $defaultMask = '********',
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * @param class-string $class
     */
    public function policyFor(string $class, string $property): FieldPolicy
    {
        return $this->resolve($class, $property)['policy'];
    }

    /**
     * The sentinel stored in place of a masked value: the per-property override, else the global default.
     *
     * @param class-string $class
     */
    public function maskFor(string $class, string $property): string
    {
        return $this->resolve($class, $property)['mask'];
    }

    /**
     * @param class-string $class
     *
     * @return array{policy: FieldPolicy, mask: string}
     */
    private function resolve(string $class, string $property): array
    {
        return $this->resolved[$class.'::'.$property] ??= $this->read($class, $property);
    }

    /**
     * @param class-string $class
     *
     * @return array{policy: FieldPolicy, mask: string}
     */
    private function read(string $class, string $property): array
    {
        $declaration = $this->findDeclaration($class, $property);

        if (null === $declaration) {
            return $this->unresolved($property);
        }

        $ignored = [] !== $declaration->getAttributes(NotAuditable::class);
        $masked = $declaration->getAttributes(AuditMasked::class);

        if ($ignored && [] !== $masked) {
            throw ConflictingFieldPolicy::onProperty($class, $property);
        }

        if ($ignored) {
            return ['policy' => FieldPolicy::Ignored, 'mask' => $this->defaultMask];
        }

        if ([] !== $masked) {
            return [
                'policy' => FieldPolicy::Masked,
                'mask' => $masked[0]->newInstance()->mask ?? $this->defaultMask,
            ];
        }

        return ['policy' => FieldPolicy::Tracked, 'mask' => $this->defaultMask];
    }

    /**
     * What to do with a key whose declaration was not found — which is two different questions.
     *
     * A plain key that resolves to nothing is a name the class does not declare, and tracking it is
     * the same permissive default every unannotated property gets. A **dotted** key is Doctrine
     * describing a change inside an embeddable, and the segments before the last are exactly where an
     * `#[AuditMasked]` would be found. Failing to walk them and then recording the value would
     * publish a secret because the resolver did not understand the mapping — the one outcome this
     * class exists to prevent. So an unresolved path is masked: the entry still says that something
     * changed, and says nothing about what.
     *
     * @return array{policy: FieldPolicy, mask: string}
     */
    private function unresolved(string $property): array
    {
        return [
            'policy' => str_contains($property, '.') ? FieldPolicy::Masked : FieldPolicy::Tracked,
            'mask' => $this->defaultMask,
        ];
    }

    /**
     * Finds the property a change set key refers to, following embedded objects.
     *
     * Doctrine reports a change inside an embeddable under a dotted key — `credentials.secret` —
     * which is not a property of the owning class. Each leading segment is therefore resolved to the
     * class holding the next one, to any depth, before the last segment is looked up.
     *
     * Doctrine's own mapping answers first, because Doctrine is what produced the key:
     * `#[ORM\Embedded(class: Credentials::class)]` is a complete declaration with or without a PHP
     * type on the property, and `embeddedClasses` carries the whole dotted path, nesting included.
     * Reflection is the fallback, for classes no entity manager knows about.
     *
     * @param class-string $class
     */
    private function findDeclaration(string $class, string $property): ?\ReflectionProperty
    {
        $path = explode('.', $property);
        $leaf = array_pop($path);

        if ([] === $path) {
            return $this->declaredProperty($class, $leaf);
        }

        $owner = $this->mappedEmbeddableAt($class, implode('.', $path))
            ?? $this->walkByReflection($class, $path);

        return null === $owner ? null : $this->declaredProperty($owner, $leaf);
    }

    /**
     * @param class-string $class
     *
     * @return class-string|null
     */
    private function mappedEmbeddableAt(string $class, string $path): ?string
    {
        // `??` and not `?->` on the array access: a path Doctrine never mapped is an ordinary answer
        // here — the caller masks it — and raising `Undefined array key` would turn deciding a policy
        // into an aborted flush for anyone who promotes warnings to exceptions.
        $mapping = $this->metadataFor($class)?->embeddedClasses[$path] ?? null;

        return null !== $mapping && class_exists($mapping->class) ? $mapping->class : null;
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>|null
     */
    private function metadataFor(string $class): ?ClassMetadata
    {
        if (null === $this->entityManager || !class_exists($class)) {
            return null;
        }

        try {
            return $this->entityManager->getClassMetadata($class);
        } catch (MappingException) {
            return null;
        }
    }

    /**
     * @param class-string $class
     * @param list<string> $path
     *
     * @return class-string|null
     */
    private function walkByReflection(string $class, array $path): ?string
    {
        $owner = $class;

        foreach ($path as $segment) {
            $type = $this->declaredProperty($owner, $segment)?->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                return null;
            }

            $embedded = $type->getName();

            if (!class_exists($embedded)) {
                return null;
            }

            $owner = $embedded;
        }

        return $owner;
    }

    /**
     * @param class-string $class
     */
    private function declaredProperty(string $class, string $property): ?\ReflectionProperty
    {
        if (!class_exists($class)) {
            return null;
        }

        for ($level = new \ReflectionClass($class); false !== $level; $level = $level->getParentClass()) {
            if ($level->hasProperty($property)) {
                return $level->getProperty($property);
            }
        }

        return null;
    }
}
