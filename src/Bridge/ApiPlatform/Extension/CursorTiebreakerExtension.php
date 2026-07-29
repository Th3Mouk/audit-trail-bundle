<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\ApiPlatform\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;

/**
 * Guarantees the audit feed's ORDER BY ends on the identifier.
 *
 * `occurredAt` is not unique, so ordering by it alone is not a total order: two rows sharing a
 * timestamp may swap between two queries, which duplicates or skips entries across pages — and
 * makes the keyset cursor, whose predicate is expressed on the identifier, unsound. Appending the
 * identifier costs nothing when it is already there and repairs the ordering when it is not.
 */
final readonly class CursorTiebreakerExtension implements QueryCollectionExtensionInterface
{
    /**
     * @param class-string $resourceClass
     */
    public function __construct(
        private string $resourceClass,
        private string $property = 'id',
        private string $direction = 'DESC',
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if ($this->resourceClass !== $resourceClass) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $field = \sprintf('%s.%s', $rootAlias, $this->property);

        if ($this->isAlreadyOrderedBy($queryBuilder, $field)) {
            return;
        }

        $queryBuilder->addOrderBy($field, $this->direction);
    }

    private function isAlreadyOrderedBy(QueryBuilder $queryBuilder, string $field): bool
    {
        $orderBy = $queryBuilder->getDQLPart('orderBy');

        if (!\is_array($orderBy)) {
            return false;
        }

        foreach ($orderBy as $expression) {
            if (!$expression instanceof OrderBy) {
                continue;
            }

            foreach ($expression->getParts() as $part) {
                if (str_starts_with(ltrim($part), $field.' ')) {
                    return true;
                }
            }
        }

        return false;
    }
}
