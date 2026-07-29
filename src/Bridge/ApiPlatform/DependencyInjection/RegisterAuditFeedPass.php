<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\ApiPlatform\DependencyInjection;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\AuditFeedOptions;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\Extension\CursorTiebreakerExtension;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\Filter\KeysetCursorFilter;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\Metadata\AuditFeedResourceMetadataCollectionFactory;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\Metadata\AuditFeedResourceNameCollectionFactory;
use Th3Mouk\AuditTrail\Entity\AuditLog;

/**
 * Registers the read-only audit feed, and nothing at all when api-platform/core is absent.
 *
 * Install it with `RegisterAuditFeedPass::register($container)` from the bundle's `build()`, never
 * with a bare `addCompilerPass()`: the pass has to run before API Platform's own `FilterPass`,
 * which snapshots every `api_platform.filter` service into the filter locator at priority 0, and
 * bundle registration order gives no guarantee on its own.
 *
 * Everything the bridge needs is read from container parameters, so the bundle extension only has
 * to expose its configuration. When the parameters are missing the pass falls back to
 * auto-detection plus the documented defaults, which is exactly what
 * `bridges.api_platform.enabled: ~` means.
 */
final class RegisterAuditFeedPass implements CompilerPassInterface
{
    public const int PRIORITY = 10;
    public const string OPTIONS = 'audit_trail.bridge.api_platform.options';
    public const string RESOURCE_NAME_FACTORY = 'audit_trail.bridge.api_platform.metadata.resource_name_collection_factory';
    public const string RESOURCE_METADATA_FACTORY = 'audit_trail.bridge.api_platform.metadata.resource_metadata_collection_factory';
    public const string CURSOR_TIEBREAKER = 'audit_trail.bridge.api_platform.query_extension.cursor_tiebreaker';
    public const string FILTER_PREFIX = 'audit_trail.bridge.api_platform.filter.';

    private const array EXACT_FILTER_PROPERTIES = [
        'actorType' => 'exact',
        'actorId' => 'exact',
        'entityClass' => 'exact',
        'entityId' => 'exact',
        'rootType' => 'exact',
        'rootId' => 'exact',
    ];

    private const array ORDERABLE_PROPERTIES = ['id' => 'DESC', 'occurredAt' => 'DESC'];

    private const string CURSOR_PROPERTY = 'id';

    public static function register(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new self(), PassConfig::TYPE_BEFORE_OPTIMIZATION, self::PRIORITY);
    }

    public function process(ContainerBuilder $container): void
    {
        if (!self::isFeedEnabled($container)) {
            return;
        }

        $container->setDefinition(self::OPTIONS, $this->options($container));
        $this->registerCursorTiebreaker($container);
        $this->registerResourceNameCollectionFactory($container);
        $this->registerResourceMetadataCollectionFactory($container, $this->registerFilters($container));
    }

    public static function isFeedEnabled(ContainerBuilder $container): bool
    {
        if (!interface_exists(ResourceMetadataCollectionFactoryInterface::class)) {
            return false;
        }

        if (!$container->has('api_platform.metadata.resource.metadata_collection_factory')) {
            return false;
        }

        if (!$container->hasDefinition('api_platform.doctrine.orm.search_filter')) {
            return false;
        }

        if (false === self::parameter($container, 'audit_trail.enabled')) {
            return false;
        }

        $bridgeEnabled = self::parameter($container, 'audit_trail.bridges.api_platform.enabled');

        return null === $bridgeEnabled || false !== $bridgeEnabled;
    }

    private function options(ContainerBuilder $container): Definition
    {
        return (new Definition(AuditFeedOptions::class, [
            self::parameter($container, 'audit_trail.bridges.api_platform.route_prefix'),
            self::parameter($container, 'audit_trail.bridges.api_platform.items_per_page'),
            self::parameter($container, 'audit_trail.bridges.api_platform.max_items_per_page'),
            self::parameter($container, 'audit_trail.bridges.api_platform.security'),
        ]))
            ->setFactory([AuditFeedOptions::class, 'of']);
    }

    /**
     * @return list<string>
     */
    private function registerFilters(ContainerBuilder $container): array
    {
        $definitions = [
            'cursor' => new Definition(KeysetCursorFilter::class, [
                self::CURSOR_PROPERTY,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]),
            'exact' => (new ChildDefinition('api_platform.doctrine.orm.search_filter'))
                ->setArguments([self::EXACT_FILTER_PROPERTIES]),
            'action' => (new ChildDefinition('api_platform.doctrine.orm.backed_enum_filter'))
                ->setArguments([['action' => null]]),
            'occurred_at' => (new ChildDefinition('api_platform.doctrine.orm.date_filter'))
                ->setArguments([['occurredAt' => null]]),
            'order' => (new ChildDefinition('api_platform.doctrine.orm.order_filter'))
                ->setArguments([self::ORDERABLE_PROPERTIES]),
        ];

        $serviceIds = [];

        foreach ($definitions as $name => $definition) {
            $serviceId = self::FILTER_PREFIX.$name;

            $container->setDefinition($serviceId, $definition->addTag('api_platform.filter'));

            $serviceIds[] = $serviceId;
        }

        return $serviceIds;
    }

    private function registerCursorTiebreaker(ContainerBuilder $container): void
    {
        $definition = (new Definition(CursorTiebreakerExtension::class, [AuditLog::class, self::CURSOR_PROPERTY]))
            ->addTag('api_platform.doctrine.orm.query_extension.collection', ['priority' => -33]);

        $container->setDefinition(self::CURSOR_TIEBREAKER, $definition);
    }

    private function registerResourceNameCollectionFactory(ContainerBuilder $container): void
    {
        $definition = new Definition(AuditFeedResourceNameCollectionFactory::class, [
            new Reference(self::RESOURCE_NAME_FACTORY.'.inner'),
        ]);
        $definition->setDecoratedService('api_platform.metadata.resource.name_collection_factory', null, 0);

        $container->setDefinition(self::RESOURCE_NAME_FACTORY, $definition);
    }

    /**
     * @param list<string> $filters
     */
    private function registerResourceMetadataCollectionFactory(ContainerBuilder $container, array $filters): void
    {
        $definition = new Definition(AuditFeedResourceMetadataCollectionFactory::class, [
            new Reference(self::RESOURCE_METADATA_FACTORY.'.inner'),
            new Reference(self::OPTIONS),
            $filters,
        ]);
        $definition->setDecoratedService('api_platform.metadata.resource.metadata_collection_factory', null, 810);

        $container->setDefinition(self::RESOURCE_METADATA_FACTORY, $definition);
    }

    private static function parameter(ContainerBuilder $container, string $name): mixed
    {
        return $container->hasParameter($name) ? $container->getParameter($name) : null;
    }
}
