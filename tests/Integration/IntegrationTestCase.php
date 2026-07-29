<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Repository\AuditLogRepository;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailKernelTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Doctrine\AuditLogEntries;
use Th3Mouk\AuditTrail\Tests\Fixtures\Doctrine\SchemaBuilder;

/**
 * The integration suite's vocabulary: a real UnitOfWork, a real transaction, real rows.
 *
 * Everything added here is something a unit test cannot say. Transactions, because atomicity
 * is a database property. Raw rows, because "the entry survived" means the columns survived.
 * An SQL window, because "capture issues no query" is only provable by watching the wire.
 *
 * The optional bridges are switched off for the whole suite. They live in `tests/Bridge`, and
 * `enabled: false` is exactly how the bundle sees "not installed" — which keeps this suite an
 * honest statement about the bundle's own core even in a checkout where api-platform and
 * gedmo happen to sit in `vendor/` as development dependencies.
 */
abstract class IntegrationTestCase extends AuditTrailKernelTestCase
{
    private const string SQL_OBSERVER_ID = 'doctrine.debug_data_holder';

    #[\Override]
    protected static function auditTrailConfig(): array
    {
        return self::withoutOptionalBridges();
    }

    /**
     * Leaves the server as it was found.
     *
     * The schema is dropped here rather than only rebuilt in `setUp` because a real server keeps
     * what an in-memory database forgets. A test that renames the trail's table leaves that table
     * behind, and its index names — which the entity fixes — are unique per schema on PostgreSQL,
     * so the next test's `audit_logs` would collide with the previous test's leftovers.
     */
    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected static function withoutOptionalBridges(array $config = []): array
    {
        return array_replace_recursive(
            [
                'bridges' => [
                    'gedmo' => ['enabled' => false],
                    'api_platform' => ['enabled' => false],
                ],
            ],
            $config,
        );
    }

    /**
     * Reboots on a different bundle configuration, optional bridges still absent.
     *
     * The outgoing schema goes first: the incoming kernel only knows how to drop the tables its
     * own mapping describes, so a table the new configuration has renamed away would survive and
     * collide with it.
     *
     * @param array<string, mixed> $config
     */
    protected function rebootWith(array $config): void
    {
        $this->dropSchema();

        $this->bootWith(self::withoutOptionalBridges($config));
    }

    /**
     * Housekeeping, so a failure inside a test is the one reported rather than the cleanup that
     * could not run afterwards.
     */
    protected function dropSchema(): void
    {
        try {
            SchemaBuilder::drop($this->em());
        } catch (\Throwable) {
            $this->connection()->close();
        }
    }

    /**
     * Runs the work inside a transaction that is then committed.
     *
     * @param \Closure(): void $work
     */
    protected function inCommittedTransaction(\Closure $work): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $work();
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        } finally {
            $this->em()->clear();
        }
    }

    /**
     * Runs the work inside a transaction that is then rolled back, whatever happens.
     *
     * The identity map is cleared afterwards so the assertions that follow read the database
     * instead of remembering what the rollback threw away.
     *
     * @param \Closure(): void $work
     */
    protected function inRolledBackTransaction(\Closure $work): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $work();
        } finally {
            $connection->rollBack();
            $this->em()->clear();
        }
    }

    protected function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    protected function auditTable(): string
    {
        return $this->em()->getClassMetadata(AuditLog::class)->getTableName();
    }

    protected function countRowsIn(string $table): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    protected function countAuditRows(): int
    {
        return $this->countRowsIn($this->auditTable());
    }

    /**
     * The trail exactly as the database holds it, columns and all.
     *
     * @return list<array<string, mixed>>
     */
    protected function auditRows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            \sprintf('SELECT * FROM %s ORDER BY occurred_at ASC, id ASC', $this->auditTable()),
        );

        return $rows;
    }

    /**
     * The trail with everything that is unique to a row removed, so two rows produced by two
     * different code paths can be compared for shape.
     *
     * @return list<array<string, mixed>>
     */
    protected function auditRowsWithoutIdentity(): array
    {
        return array_map(
            static function (array $row): array {
                unset($row['id'], $row['occurred_at']);

                foreach (['changes', 'metadata'] as $jsonColumn) {
                    $encoded = $row[$jsonColumn] ?? null;
                    $row[$jsonColumn] = \is_string($encoded) ? json_decode($encoded, true) : $encoded;
                }

                return $row;
            },
            $this->auditRows(),
        );
    }

    /**
     * Everything the database holds about the trail, as one searchable string.
     *
     * A masked value must not appear anywhere — not in `changes`, not in a label, not in
     * metadata — and the cheapest way to say that is to look at all of it at once.
     *
     * Built from the identity-stripped rows because the primary key is stored as raw binary on
     * platforms without a native UUID type, and one unencodable byte would turn every
     * "the secret is absent" assertion into a vacuous one. The dump is asserted non-empty for
     * the same reason: a silent encoding failure must fail the test, not pass it.
     */
    protected function auditTableDump(): string
    {
        $dump = json_encode(
            $this->auditRowsWithoutIdentity(),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        self::assertIsString($dump, 'The trail could not be dumped, so nothing can be said about its contents.');
        self::assertNotSame('[]', $dump, 'An empty trail proves nothing about what it does not contain.');

        return $dump;
    }

    protected function logs(): AuditLogRepository
    {
        $repository = $this->em()->getRepository(AuditLog::class);
        \assert($repository instanceof AuditLogRepository);

        return $repository;
    }

    /**
     * Executes one of the repository's feed builders.
     *
     * The builders arrive as parameters, which is the one shape `reportDynamicQueryBuilders`
     * cannot trace back to a `createQueryBuilder()` call. Keeping the execution in a single
     * helper keeps that to one place instead of one per call site.
     *
     * @return list<AuditLog>
     */
    protected function logsOf(QueryBuilder $queryBuilder): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $queryBuilder->getQuery()->getResult();

        return $logs;
    }

    /**
     * @return list<AuditEntry>
     */
    protected function entriesOf(QueryBuilder $queryBuilder): array
    {
        return array_map(AuditLogEntries::toEntry(...), $this->logsOf($queryBuilder));
    }

    /**
     * The SQL the work actually sent to the server, in order.
     *
     * Observed through DoctrineBundle's own debug middleware, which wraps the DBAL driver in
     * debug mode and records every statement. Using Doctrine's instrumentation rather than a
     * bespoke hook means the window shows what the driver really executed, including the
     * statements the ORM emits on its own behalf.
     *
     * @param \Closure(): void $work
     *
     * @return list<string>
     */
    protected function sqlExecutedDuring(\Closure $work): array
    {
        $before = \count($this->executedSql());

        $work();

        return \array_slice($this->executedSql(), $before);
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    protected static function selectsAmong(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn (string $statement): bool => str_starts_with(strtoupper(ltrim($statement)), 'SELECT'),
        ));
    }

    /**
     * @param list<string> $statements
     */
    protected static function describeSql(array $statements): string
    {
        if ([] === $statements) {
            return '  (nothing)';
        }

        return implode("\n", array_map(
            static fn (string $statement): string => '  '.preg_replace('/\s+/', ' ', trim($statement)),
            $statements,
        ));
    }

    /**
     * @return list<string>
     */
    private function executedSql(): array
    {
        $statements = [];

        foreach ($this->sqlObserver()->getData() as $perConnection) {
            if (!\is_array($perConnection)) {
                continue;
            }

            foreach ($perConnection as $query) {
                $sql = \is_array($query) ? ($query['sql'] ?? null) : null;

                if (\is_string($sql)) {
                    $statements[] = $sql;
                }
            }
        }

        return $statements;
    }

    private function sqlObserver(): DebugDataHolder
    {
        self::assertTrue(
            self::getContainer()->has(self::SQL_OBSERVER_ID),
            'Observing SQL needs Doctrine profiling, which is on in debug mode.',
        );

        $observer = self::getContainer()->get(self::SQL_OBSERVER_ID);
        \assert($observer instanceof DebugDataHolder);

        return $observer;
    }
}
