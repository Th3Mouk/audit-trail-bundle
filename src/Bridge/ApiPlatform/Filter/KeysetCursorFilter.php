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
 * The feed's cursor: one lexicographic predicate over the whole sort key.
 *
 * Two problems make this a filter of its own rather than API Platform's `RangeFilter`.
 *
 * The first is the identifier's type. `RangeFilter` coerces every bound through `is_numeric()` and
 * drops what fails, which silently disables a cursor on a UUID primary key. Here the bound becomes a
 * real `Uuid`, bound with the `uuid` Doctrine type, so the driver decides the representation rather
 * than the query string.
 *
 * The second is that a cursor must cover the *entire* sort key at once. The feed is ordered by
 * `(occurredAt, id)`, and API Platform's cursor links emit one bound per declared field —
 * `occurredAt[lt]=…&id[lt]=…`. Applied as two independent conditions those mean
 * `occurredAt < :o AND id < :i`, which is not the same thing: an older row carrying a larger
 * identifier is silently dropped, and a row sharing the cursor's instant comes back on two
 * consecutive pages. This filter therefore reads both bounds together and emits the only predicate
 * that walks a composite key correctly:
 *
 *     occurredAt < :o OR (occurredAt = :o AND id < :i)
 *
 * Both halves are necessary because neither is sufficient. `occurredAt` is not unique — one flush
 * stamps all of its entries with the same instant — and `id` is chronological only for entries the
 * listener captured, since a backfill writes historical timestamps under fresh, larger UUID v7
 * identifiers.
 */
final readonly class KeysetCursorFilter implements FilterInterface
{
    private const array FORWARD = ['lt' => '<', 'lte' => '<='];
    private const array BACKWARD = ['gt' => '>', 'gte' => '>='];

    public function __construct(
        private string $timeProperty = 'occurredAt',
        private string $identifierProperty = 'id',
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $filters = $context['filters'] ?? [];

        if (!\is_array($filters)) {
            return;
        }

        foreach ([...self::FORWARD, ...self::BACKWARD] as $operator => $comparison) {
            $this->applyBound($queryBuilder, $queryNameGenerator, $filters, $operator, $comparison);
        }
    }

    /**
     * @return array<string, array{property: string, type: string, required: bool, description: string, schema: array<string, mixed>}>
     */
    public function getDescription(string $resourceClass): array
    {
        $description = [];

        foreach (array_keys([...self::FORWARD, ...self::BACKWARD]) as $operator) {
            $towards = isset(self::FORWARD[$operator]) ? 'older than' : 'newer than';

            $description[\sprintf('%s[%s]', $this->timeProperty, $operator)] = [
                'property' => $this->timeProperty,
                'type' => 'string',
                'required' => false,
                'description' => \sprintf(
                    'Keyset cursor, timestamp half. Paired with %s[%s] it returns the entries %s the cursor in '
                    .'feed order. Take both halves from the hydra next/previous links; one alone is ignored. To '
                    .'window the feed by date instead, use %s[before] / %s[after].',
                    $this->identifierProperty,
                    $operator,
                    $towards,
                    $this->timeProperty,
                    $this->timeProperty,
                ),
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ];

            $description[\sprintf('%s[%s]', $this->identifierProperty, $operator)] = [
                'property' => $this->identifierProperty,
                'type' => 'string',
                'required' => false,
                'description' => \sprintf(
                    'Keyset cursor, identifier half. Breaks the tie when several entries share the cursor\'s %s. '
                    .'Pair it with %s[%s].',
                    $this->timeProperty,
                    $this->timeProperty,
                    $operator,
                ),
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ];
        }

        return $description;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyBound(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        array $filters,
        string $operator,
        string $comparison,
    ): void {
        $instant = $this->instantBound($filters, $operator);
        $identifier = $this->uuidBound($filters, $operator);

        if (null === $instant || null === $identifier) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $timeParameter = $queryNameGenerator->generateParameterName($this->timeProperty);
        $identifierParameter = $queryNameGenerator->generateParameterName($this->identifierProperty);

        // Strict on the leading field, then the tie broken on the identifier: `lte` and `gte` differ
        // from `lt` and `gt` only in whether the cursor row itself is kept, which is a decision about
        // the identifier, never about the instant.
        $queryBuilder
            ->andWhere(\sprintf(
                '(%1$s.%2$s %3$s :%4$s OR (%1$s.%2$s = :%4$s AND %1$s.%5$s %6$s :%7$s))',
                $alias,
                $this->timeProperty,
                rtrim($comparison, '='),
                $timeParameter,
                $this->identifierProperty,
                $comparison,
                $identifierParameter,
            ))
            ->setParameter($timeParameter, $instant)
            ->setParameter($identifierParameter, $identifier, UuidType::NAME);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function uuidBound(array $filters, string $operator): ?Uuid
    {
        $bound = $this->rawBound($filters, $this->identifierProperty, $operator);

        if (null === $bound) {
            return null;
        }

        if (Uuid::isValid($bound)) {
            return Uuid::fromString($bound);
        }

        $this->report($this->identifierProperty, $operator, 'not a UUID');

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function instantBound(array $filters, string $operator): ?\DateTimeImmutable
    {
        $bound = $this->rawBound($filters, $this->timeProperty, $operator);

        if (null === $bound) {
            return null;
        }

        try {
            return new \DateTimeImmutable($bound);
        } catch (\Exception) {
            $this->report($this->timeProperty, $operator, 'not a date-time');

            return null;
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function rawBound(array $filters, string $property, string $operator): ?string
    {
        $bounds = $filters[$property] ?? null;

        if (!\is_array($bounds)) {
            return null;
        }

        $bound = $bounds[$operator] ?? null;

        return \is_string($bound) && '' !== $bound ? $bound : null;
    }

    private function report(string $property, string $operator, string $reason): void
    {
        $this->logger?->notice('Invalid audit trail cursor ignored', [
            'property' => $property,
            'operator' => $operator,
            'reason' => $reason,
        ]);
    }
}
