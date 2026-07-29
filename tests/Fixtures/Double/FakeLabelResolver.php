<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Th3Mouk\AuditTrail\Capture\LabelResolverInterface;

/**
 * Supplies labels without reading any attribute.
 *
 * Lets a capture test state the label it expects to travel onto the entry, instead of
 * re-testing the label resolver through it.
 */
final readonly class FakeLabelResolver implements LabelResolverInterface
{
    /**
     * @param \Closure(object): (string|null) $label
     */
    private function __construct(
        private \Closure $label,
    ) {
    }

    public static function alwaysReturning(?string $label): self
    {
        return new self(static fn (object $entity): ?string => $label);
    }

    public static function returningNothing(): self
    {
        return new self(static fn (object $entity): ?string => null);
    }

    /**
     * @param \Closure(object): (string|null) $label
     */
    public static function deciding(\Closure $label): self
    {
        return new self($label);
    }

    public function resolve(object $entity): ?string
    {
        return ($this->label)($entity);
    }
}
