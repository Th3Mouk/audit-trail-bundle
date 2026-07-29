<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Range predicate on a UUID identifier, so cursor pagination can express `id[lt]` / `id[gt]`.
 *
 * API Platform's own `RangeFilter` coerces every bound through `is_numeric()` and drops whatever
 * fails, which silently disables the cursor on a UUID primary key. This filter compares the
 * column against a real `Uuid`, bound with the `uuid` Doctrine type so the driver decides the
 * representation instead of the query string.
 */
final readonly class KeysetCursorFilter implements FilterInterface
{
    private const array COMPARISONS = [
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    public function __construct(
        private string $property = 'id',
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $bounds = $context['filters'][$this->property] ?? null;

        if (!\is_array($bounds)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        foreach ($bounds as $operator => $bound) {
            $comparison = self::COMPARISONS[$operator] ?? null;

            if (null === $comparison || !\is_string($bound)) {
                continue;
            }

            $cursor = $this->toUuid($bound, $operator);

            if (null === $cursor) {
                continue;
            }

            $parameterName = $queryNameGenerator->generateParameterName($this->property);

            $queryBuilder
                ->andWhere(\sprintf('%s.%s %s :%s', $alias, $this->property, $comparison, $parameterName))
                ->setParameter($parameterName, $cursor, UuidType::NAME);
        }
    }

    /**
     * @return array<string, array{property: string, type: string, required: bool, description: string, schema: array<string, mixed>}>
     */
    public function getDescription(string $resourceClass): array
    {
        $description = [];

        foreach (array_keys(self::COMPARISONS) as $operator) {
            $description[\sprintf('%s[%s]', $this->property, $operator)] = [
                'property' => $this->property,
                'type' => 'string',
                'required' => false,
                'description' => \sprintf('Keyset cursor: keep only rows whose "%s" is %s the given UUID.', $this->property, self::COMPARISONS[$operator]),
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ];
        }

        return $description;
    }

    private function toUuid(string $bound, string $operator): ?Uuid
    {
        if (Uuid::isValid($bound)) {
            return Uuid::fromString($bound);
        }

        $this->logger?->notice('Invalid audit trail cursor ignored', [
            'property' => $this->property,
            'operator' => $operator,
        ]);

        return null;
    }
}
