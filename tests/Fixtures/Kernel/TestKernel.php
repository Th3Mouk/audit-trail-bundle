<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
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
    #[\Override]
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

        // These kernels declare their configuration in PHP methods, so there is no config file for
        // Symfony to watch: editing `doctrineConfig()` would otherwise leave a stale compiled
        // container in place and the suite would test the previous configuration. Tracking the
        // kernel classes themselves makes any edit to them invalidate the container.
        $container->addObjectResource($this);
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
                // `use_savepoints` is deliberately not set: DoctrineBundle 3 removed the option
                // because DBAL 4 always uses savepoints for nested transactions, and setting it
                // there is a configuration error. Nested transactions behave identically on both.
            ],
            'orm' => $this->ormConfig(),
        ];
    }

    /**
     * `enable_native_lazy_objects` is set only when the installed DoctrineBundle declares it.
     *
     * The supported range disagrees about this option three ways: the oldest DoctrineBundle has no
     * such node and setting it is a configuration error; the middle of the range has it and *needs*
     * it, because Symfony 8 removed `LazyGhostTrait` and Doctrine then has no other way to build a
     * proxy; the newest has it too. Asking the installed Configuration whether it knows the option
     * is therefore more honest than pinning a version number this suite would have to keep guessing.
     *
     * @return array<string, mixed>
     */
    protected function ormConfig(): array
    {
        $orm = $this->entityManagerConfig();

        if (self::declaresNativeLazyObjectsOption()) {
            $orm['enable_native_lazy_objects'] = true;
        }

        return $orm;
    }

    /**
     * The options that belong to one entity manager rather than to `doctrine.orm` as a whole, so a
     * kernel declaring several managers can give each of them the same setup.
     *
     * @return array<string, mixed>
     */
    protected function entityManagerConfig(): array
    {
        return [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'mappings' => $this->entityMappings(),
        ];
    }

    protected static function declaresNativeLazyObjectsOption(): bool
    {
        static $declares = null;

        if (null === $declares) {
            $configuration = new \ReflectionClass(DoctrineConfiguration::class)->getFileName();

            $declares = \is_string($configuration)
                && str_contains((string) file_get_contents($configuration), 'enable_native_lazy_objects');
        }

        return $declares;
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
        $checkoutHash = substr(hash('xxh128', $this->getProjectDir()), 0, 12);

        // Every configuration this kernel produces feeds the key, not just the bundle's own slice.
        // These kernels configure themselves in PHP, so there is no config file whose mtime Symfony
        // could watch: a change to the Doctrine or framework block would otherwise keep serving a
        // container compiled from the previous one, and the suite would quietly test the old setup.
        $configHash = substr(hash('xxh128', serialize([
            $this->auditTrailConfig(),
            $this->frameworkConfig(),
            $this->doctrineConfig(),
        ])), 0, 12);

        return \sprintf('%s-%s-%s', $shortName, $checkoutHash, $configHash);
    }
}
