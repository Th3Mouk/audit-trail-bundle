<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\CacheWarmer\DoctrineMetadataCacheWarmer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;
use Symfony\Component\HttpKernel\KernelInterface;
use Th3Mouk\AuditTrail\Metadata\AuditTypeWarmer;
use Th3Mouk\AuditTrail\Tests\Case\RestoresFrameworkErrorHandlers;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\DoctrineOnlyKernel;

/**
 * The one neighbour this warmer cannot get out of the way of by priority alone.
 *
 * DoctrineBundle installs its own `DoctrineMetadataCacheWarmer` at `kernel.cache_warmer` priority
 * 1000, but only while `kernel.debug` is false — this kernel, unlike the rest of the suite, boots
 * that way for exactly this reason. That warmer throws a `LogicException` if the metadata factory
 * has already loaded anything by the time it runs.
 *
 * `CacheWarmerAggregate` does not sort every registered warmer into one priority-ordered run: a
 * freshly compiled container warms its non-optional warmers immediately, as a side effect of
 * `Kernel::initializeContainer()`, and only reaches the optional ones — DoctrineBundle's
 * included — in a later, separate pass, the one `cache:warmup` and `cache:clear` each trigger
 * explicitly afterwards. `AuditTypeWarmer::isOptional()` is hard-coded `false`, so no priority on
 * its tag can defer it into that second pass — it always lands in the first one, before
 * DoctrineBundle's warmer ever runs.
 *
 * Two tests, because the hazard and the fix are separate claims. The first pins the hazard itself,
 * with this bundle out of the way: *anything* that loads metadata before DoctrineBundle's warmer
 * runs breaks it, which is what makes a `kernel.cache_warmer` priority on `AuditTypeWarmer` useless
 * — the two never run within the same priority-ordered pass for such a priority to order. The
 * second is the actual regression: `AuditTypeWarmer`, wired for real, must not be that "anything".
 */
#[CoversClass(AuditTypeWarmer::class)]
final class CacheWarmerOrderingTest extends KernelTestCase
{
    use RestoresFrameworkErrorHandlers;

    private const string ENVIRONMENT = 'cache_warmer_ordering';

    #[\Override]
    protected static function getKernelClass(): string
    {
        return DoctrineOnlyKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DoctrineOnlyKernel(self::ENVIRONMENT, false);
    }

    /**
     * Every test in this file depends on booting into a cache directory Symfony has never
     * compiled a container into before — that is the only way `Kernel::initializeContainer()`
     * runs its implicit, non-optional-only warm-up pass at all. A directory left over from a
     * previous run would be found fresh (its container class already matches every tracked
     * resource) and skip that pass entirely, silently turning both tests below into no-ops.
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::removeDirectory(new DoctrineOnlyKernel(self::ENVIRONMENT, false)->getCacheDir());
    }

    /**
     * `KernelTestCase::tearDown()` shuts the kernel down but does not restore the error and
     * exception handlers it installed while booting; see `RestoresFrameworkErrorHandlers`.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreFrameworkErrorHandlers();
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }

    /**
     * The control: proof that the hazard is real and belongs to Symfony and DoctrineBundle, not
     * to this bundle. Nothing here calls `AuditTypeWarmer` — the metadata factory is primed by
     * hand, standing in for whatever non-optional warmer got there first.
     */
    public function testDoctrinesOwnWarmerThrowsWhenAnythingLoadsMetadataFirst(): void
    {
        [$aggregate, $cacheDir, $buildDir] = $this->realDoctrineWarmerAndAggregate();

        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->getMetadataFactory()->getAllMetadata();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must load metadata first');

        $aggregate->enableOptionalWarmers();
        $aggregate->warmUp($cacheDir, $buildDir);
    }

    /**
     * The regression: the real `AuditTypeWarmer`, run exactly where production runs it — the
     * first, non-optional-only pass — must not be the thing that trips the hazard above for
     * DoctrineBundle's warmer, run afterwards exactly as `cache:warmup` runs it.
     */
    public function testAuditTypeWarmerRunningFirstStillLetsDoctrinesOwnWarmerCompleteAfterwards(): void
    {
        [$aggregate, $cacheDir, $buildDir] = $this->realDoctrineWarmerAndAggregate();

        // The pass every freshly compiled container runs on its own, inside
        // Kernel::initializeContainer(): optional warmers excluded, so only AuditTypeWarmer —
        // non-optional — actually runs.
        $aggregate->warmUp($cacheDir, $buildDir);

        // The pass `cache:warmup` and `cache:clear` each add explicitly, afterwards: optional
        // warmers included, DoctrineBundle's metadata warmer among them. Before this bundle's
        // fix, this call threw the same LogicException pinned above — DoctrineBundle's warmer
        // found metadata already loaded by AuditTypeWarmer's pass and refused to proceed.
        $aggregate->enableOptionalWarmers();
        $aggregate->warmUp($cacheDir, $buildDir);

        self::assertFileExists(
            $buildDir.'/doctrine/orm/default_metadata.php',
            'DoctrineBundle writes this file only once its own warmer has run to completion — the strongest available proof that it did not throw.',
        );
    }

    /**
     * @return array{CacheWarmerAggregate, string, string}
     */
    private function realDoctrineWarmerAndAggregate(): array
    {
        $container = self::getContainer();

        self::assertFalse(
            $container->getParameter('kernel.debug'),
            'DoctrineBundle only registers its own metadata warmer while kernel.debug is false.',
        );
        self::assertTrue(
            $container->has('doctrine.orm.default_metadata_cache_warmer'),
            'This test is only meaningful while DoctrineBundle registers its own metadata warmer.',
        );

        $doctrineWarmer = $container->get('doctrine.orm.default_metadata_cache_warmer');
        self::assertInstanceOf(DoctrineMetadataCacheWarmer::class, $doctrineWarmer);
        self::assertTrue(
            $doctrineWarmer->isOptional(),
            'The whole hazard depends on DoctrineBundle declaring its warmer optional; a change there would make this test meaningless rather than failing it.',
        );

        $aggregate = $container->get('cache_warmer');
        self::assertInstanceOf(CacheWarmerAggregate::class, $aggregate);

        $cacheDir = $container->getParameter('kernel.cache_dir');
        $buildDir = $container->getParameter('kernel.build_dir');
        self::assertIsString($cacheDir);
        self::assertIsString($buildDir);

        return [$aggregate, $cacheDir, $buildDir];
    }
}
