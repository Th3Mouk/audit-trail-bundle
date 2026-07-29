<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Th3Mouk\AuditTrail\AuditTrailBundle;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeActorResolver;

/**
 * The common ground of the three test kernels.
 *
 * Every subclass boots the bundle for real against a real database; what differs is which
 * optional dependency is present. Keeping that difference in the bundle list — rather than
 * in a flag — is what makes "the bundle needs none of them" a testable statement instead of
 * a promise in a README.
 *
 * Per-test configuration arrives through the constructor, and the cache directory is keyed
 * by it, so two tests that configure the bundle differently never share a compiled
 * container.
 */
abstract class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $auditTrailOverrides
     */
    public function __construct(
        string $environment = 'test',
        bool $debug = true,
        protected readonly array $auditTrailOverrides = [],
    ) {
        parent::__construct($environment, $debug);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new AuditTrailBundle();
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return \sprintf('%s/audit-trail-bundle/%s/%s/cache', sys_get_temp_dir(), $this->fingerprint(), $this->environment);
    }

    #[\Override]
    public function getLogDir(): string
    {
        return \sprintf('%s/audit-trail-bundle/%s/%s/log', sys_get_temp_dir(), $this->fingerprint(), $this->environment);
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditTrailConfig(): array
    {
        return $this->auditTrailOverrides;
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', $this->frameworkConfig());
        $container->loadFromExtension('doctrine', $this->doctrineConfig());
        $container->loadFromExtension('audit_trail', $this->auditTrailConfig());

        $this->registerTestDoubles($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    protected function build(ContainerBuilder $container): void
    {
        $spyOnAuditStorage = new SpyOnAuditStoragePass();

        $container->addObjectResource($spyOnAuditStorage);
        $container->addCompilerPass($spyOnAuditStorage);
    }

    /**
     * @return array<string, mixed>
     */
    protected function frameworkConfig(): array
    {
        return [
            'test' => true,
            'secret' => 'audit-trail-bundle-tests',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function doctrineConfig(): array
    {
        return [
            'dbal' => [
                'url' => self::databaseUrl(),
                'types' => [UuidType::NAME => UuidType::class],
                'use_savepoints' => true,
            ],
            'orm' => [
                'enable_native_lazy_objects' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'mappings' => $this->entityMappings(),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function entityMappings(): array
    {
        return [
            'AuditTrailFixtures' => [
                'type' => 'attribute',
                'dir' => \dirname(__DIR__).'/Entity',
                'prefix' => 'Th3Mouk\AuditTrail\Tests\Fixtures\Entity',
                'is_bundle' => false,
            ],
        ];
    }

    protected function registerTestDoubles(ContainerBuilder $container): void
    {
        $container
            ->register(FakeActorResolver::class, FakeActorResolver::class)
            ->setPublic(true)
            ->addTag('audit_trail.actor_resolver', ['priority' => 1024]);
    }

    public static function databaseUrl(): string
    {
        $url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;

        return \is_string($url) && '' !== $url ? $url : 'sqlite:///:memory:';
    }

    private function fingerprint(): string
    {
        $shortName = new \ReflectionClass($this)->getShortName();
        $configHash = substr(hash('xxh128', serialize($this->auditTrailConfig())), 0, 12);
        $checkoutHash = substr(hash('xxh128', $this->getProjectDir()), 0, 12);

        return \sprintf('%s-%s-%s', $shortName, $checkoutHash, $configHash);
    }
}
