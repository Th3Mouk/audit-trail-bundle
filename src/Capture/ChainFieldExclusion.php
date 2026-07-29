<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The union of every contributor's answer.
 *
 * Merging rather than short-circuiting is the safe direction: an exclusion only ever removes a
 * field from an entry, so consulting all of them cannot smuggle anything in, and two
 * contributors naming the same field is not a conflict. Order is therefore irrelevant.
 *
 * Contributors are collected from the `audit_trail.field_exclusion` tag.
 */
final readonly class ChainFieldExclusion implements FieldExclusionInterface
{
    /**
     * @param iterable<FieldExclusionInterface> $contributors
     */
    public function __construct(
        private iterable $contributors = [],
    ) {
    }

    public function excludedFields(EntityManagerInterface $entityManager, object $entity): array
    {
        $excluded = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->excludedFields($entityManager, $entity) as $field) {
                $excluded[$field] = true;
            }
        }

        return array_keys($excluded);
    }
}
