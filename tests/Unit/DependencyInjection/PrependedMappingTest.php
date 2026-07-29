<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Th3Mouk\AuditTrail\DependencyInjection\AuditTrailExtension;
use Th3Mouk\AuditTrail\DependencyInjection\Configuration;
use Th3Mouk\AuditTrail\Tests\Unit\Support\DoctrineExtensionStub;

/**
 * Which entity manager the `AuditLog` mapping is installed on.
 *
 * `prepend()` runs before the configuration is processed, so it reads the raw layers itself — and
 * has to merge them the way Symfony will. An application configures a bundle in
 * `config/packages/audit_trail.php` and overrides it in `config/packages/{env}/audit_trail.php`;
 * the Config component merges left to right, so for a scalar the **last** layer is the effective
 * one. Reading the first would map the entity on one manager while `load()` — which reads the
 * merged configuration — binds every service to another: an entity mapped where nothing writes it,
 * and no table at all on the manager that does.
 */
#[CoversClass(AuditTrailExtension::class)]
final class PrependedMappingTest extends TestCase
{
    public function testAnEnvironmentOverrideDecidesWhereTheEntityIsMapped(): void
    {
        self::assertSame(
            'secondary',
            $this->managerTheMappingLandsOn(
                ['entity_manager' => 'primary'],
                ['entity_manager' => 'secondary'],
            ),
        );
    }

    public function testALayerThatSaysNothingLeavesTheEarlierDeclarationAlone(): void
    {
        self::assertSame(
            'primary',
            $this->managerTheMappingLandsOn(
                ['entity_manager' => 'primary'],
                ['table_name' => 'audit_logs_v2'],
            ),
        );
    }

    /**
     * An explicit `null` is a declaration too — "go back to Doctrine's default manager".
     *
     * It is the only way an environment can undo what the common configuration set, and the merged
     * configuration honours it, so `prepend()` has to as well. Reading only strings makes a null
     * invisible: the mapping stays where the earlier layer put it while every service moves.
     */
    public function testAnEnvironmentOverrideCanUndoAnEarlierDeclaration(): void
    {
        self::assertSame(
            'default',
            $this->managerTheMappingLandsOn(
                ['entity_manager' => 'primary'],
                ['entity_manager' => null],
            ),
        );
    }

    public function testUndoingIsAlsoTheSameAnswerLoadWouldGive(): void
    {
        $container = $this->containerConfiguredWith(
            ['entity_manager' => 'primary'],
            ['entity_manager' => null],
        );

        new AuditTrailExtension()->prepend($container);

        self::assertNull($this->processedEntityManagerName($container));
        self::assertSame('default', $this->mappedManagerOf($container));
    }

    /**
     * The invariant behind the two cases above: one answer, whoever asks.
     */
    public function testPrependAndLoadResolveTheSameManager(): void
    {
        $container = $this->containerConfiguredWith(
            ['entity_manager' => 'primary'],
            ['entity_manager' => 'secondary'],
        );

        new AuditTrailExtension()->prepend($container);

        self::assertSame(
            $this->processedEntityManagerName($container),
            $this->mappedManagerOf($container),
            'prepend() and load() must agree, or the mapping and the services end up on two managers.',
        );
    }

    /**
     * With nothing declared, Doctrine's own configuration decides — and it is read in layers too.
     */
    public function testDoctrinesOwnDefaultIsReadWithTheSameRule(): void
    {
        $container = $this->containerConfiguredWith();
        $container->loadFromExtension('doctrine', ['orm' => ['default_entity_manager' => 'first']]);
        $container->loadFromExtension('doctrine', ['orm' => ['default_entity_manager' => 'last']]);

        new AuditTrailExtension()->prepend($container);

        self::assertSame('last', $this->mappedManagerOf($container));
    }

    /**
     * And undoing works there too: with no default left, Doctrine's own fallback applies.
     */
    public function testDoctrinesDefaultCanBeUndoneAsWell(): void
    {
        $container = $this->containerConfiguredWith();
        $container->loadFromExtension('doctrine', ['orm' => ['default_entity_manager' => 'named']]);
        $container->loadFromExtension('doctrine', ['orm' => ['default_entity_manager' => null]]);

        new AuditTrailExtension()->prepend($container);

        self::assertSame('default', $this->mappedManagerOf($container));
    }

    /**
     * @param array<string, mixed> ...$layers
     */
    private function managerTheMappingLandsOn(array ...$layers): string
    {
        $container = $this->containerConfiguredWith(...$layers);

        new AuditTrailExtension()->prepend($container);

        return $this->mappedManagerOf($container);
    }

    /**
     * @param array<string, mixed> ...$layers
     */
    private function containerConfiguredWith(array ...$layers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new AuditTrailExtension());
        $container->registerExtension(new DoctrineExtensionStub());

        foreach ($layers as $layer) {
            $container->loadFromExtension('audit_trail', $layer);
        }

        return $container;
    }

    private function mappedManagerOf(ContainerBuilder $container): string
    {
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $managers = $config['orm']['entity_managers'] ?? null;

            if (\is_array($managers) && [] !== $managers) {
                $name = array_key_first($managers);
                self::assertIsString($name);

                return $name;
            }
        }

        self::fail('prepend() registered no mapping at all.');
    }

    private function processedEntityManagerName(ContainerBuilder $container): ?string
    {
        $extension = new AuditTrailExtension();

        /** @var array<string, mixed> $processed */
        $processed = new \ReflectionMethod($extension, 'processConfiguration')
            ->invoke($extension, new Configuration(), $container->getExtensionConfig('audit_trail'));

        $name = $processed['entity_manager'] ?? null;
        self::assertTrue(null === $name || \is_string($name));

        return $name;
    }
}
