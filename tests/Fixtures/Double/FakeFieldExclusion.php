<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Doctrine\ORM\EntityManagerInterface;
use Th3Mouk\AuditTrail\Capture\FieldExclusionInterface;

/**
 * A contributor that excludes exactly the fields a test names.
 */
final readonly class FakeFieldExclusion implements FieldExclusionInterface
{
    /**
     * @param list<string> $fields
     */
    private function __construct(
        private array $fields,
    ) {
    }

    public static function excluding(string ...$fields): self
    {
        return new self(array_values($fields));
    }

    public static function excludingNothing(): self
    {
        return new self([]);
    }

    public function excludedFields(EntityManagerInterface $entityManager, object $entity): array
    {
        return $this->fields;
    }
}
