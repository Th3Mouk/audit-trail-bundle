<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Case;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Tests\Fixtures\Doctrine\AuditLogEntries;
use Th3Mouk\AuditTrail\Tests\Fixtures\Doctrine\SchemaBuilder;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeActorResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\RecordingAuditStorage;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\RecordingLogger;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\DoctrineOnlyKernel;

/**
 * Boots one of the test kernels against a real database and reads the trail back.
 *
 * `recordedEntries()` returns persisted rows rebuilt as AuditEntry objects, so an
 * integration test asserts on what actually survived the transaction — column widths, JSON
 * encoding and all — using exactly the vocabulary the unit suite uses. `spiedEntries()` is
 * there for the rarer case where what capture *handed to storage* is the point.
 *
 * Override `kernelUnderTest()` to choose the kernel and `auditTrailConfig()` to choose the
 * bundle configuration. Both are static because the kernel is built before the test method
 * runs; for a per-test variation, call `bootWith()`.
 */
abstract class AuditTrailKernelTestCase extends KernelTestCase
{
    use AuditEntryAssertions;
    use AuditEntryBuilders;
    use RestoresFrameworkErrorHandlers;

    /**
     * @return class-string<KernelInterface>
     */
    protected static function kernelUnderTest(): string
    {
        return DoctrineOnlyKernel::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function auditTrailConfig(): array
    {
        return [];
    }

    #[\Override]
    protected static function getKernelClass(): string
    {
        return static::kernelUnderTest();
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        $class = static::getKernelClass();

        /** @var array<string, mixed> $config */
        $config = $options['audit_trail'] ?? static::auditTrailConfig();

        /** @var KernelInterface $kernel */
        $kernel = new $class(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
            $config,
        );

        return $kernel;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        SchemaBuilder::recreate($this->em());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreFrameworkErrorHandlers();
    }

    /**
     * Reboots on a different bundle configuration, from inside a test method.
     *
     * The database goes with the old kernel, so call this before arranging anything.
     *
     * @param array<string, mixed> $auditTrailConfig
     */
    protected function bootWith(array $auditTrailConfig): void
    {
        self::ensureKernelShutdown();
        self::bootKernel(['audit_trail' => $auditTrailConfig]);
        SchemaBuilder::recreate($this->em());
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert(\is_object($service));

        return $service;
    }

    /**
     * Persists the arrangement and forgets whatever it recorded, so the assertions only see
     * the behaviour under test.
     */
    protected function given(object ...$entities): void
    {
        $this->save(...$entities);
        $this->forgetRecordedEntries();
    }

    protected function save(object ...$entities): void
    {
        $em = $this->em();

        foreach ($entities as $entity) {
            $em->persist($entity);
        }

        $em->flush();
    }

    protected function remove(object ...$entities): void
    {
        $em = $this->em();

        foreach ($entities as $entity) {
            $em->remove($entity);
        }

        $em->flush();
    }

    protected function forgetRecordedEntries(): void
    {
        $this->em()->createQuery(\sprintf('DELETE FROM %s l', AuditLog::class))->execute();
        $this->spy()?->forget();
    }

    /**
     * @return list<AuditEntry>
     */
    protected function recordedEntries(): array
    {
        return AuditLogEntries::all($this->em());
    }

    /**
     * @return list<AuditEntry>
     */
    protected function spiedEntries(): array
    {
        $spy = $this->spy();

        self::assertNotNull($spy, 'No storage spy is wired: the audit storage is not reachable by its interface.');

        return $spy->entries();
    }

    protected function spy(): ?RecordingAuditStorage
    {
        if (!self::getContainer()->has(RecordingAuditStorage::class)) {
            return null;
        }

        $spy = self::getContainer()->get(RecordingAuditStorage::class);
        \assert($spy instanceof RecordingAuditStorage);

        return $spy;
    }

    protected function recordedLogs(): ?RecordingLogger
    {
        if (!self::getContainer()->has(RecordingLogger::class)) {
            return null;
        }

        $logger = self::getContainer()->get(RecordingLogger::class);
        \assert($logger instanceof RecordingLogger);

        return $logger;
    }

    protected function actor(): FakeActorResolver
    {
        $resolver = self::getContainer()->get(FakeActorResolver::class);
        \assert($resolver instanceof FakeActorResolver);

        return $resolver;
    }

    /**
     * @param array<string, string> $server
     */
    protected function get(string $uri, array $server = []): Response
    {
        return $this->handle(Request::create($uri, 'GET', server: $server));
    }

    /**
     * Requests are handled by the booted kernel directly rather than through a browser,
     * because the fixture application deliberately installs no browser-kit.
     */
    protected function handle(Request $request): Response
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof HttpKernelInterface);

        return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }
}
