<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Metadata;

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

    public function __construct(
        private readonly string $defaultMask = '********',
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
            return ['policy' => FieldPolicy::Tracked, 'mask' => $this->defaultMask];
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
     * @param class-string $class
     */
    private function findDeclaration(string $class, string $property): ?\ReflectionProperty
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
