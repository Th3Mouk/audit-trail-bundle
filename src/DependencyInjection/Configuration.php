<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The `audit_trail` configuration tree.
 *
 * Every option is documented inline: `bin/console config:dump-reference audit_trail`
 * is the reference an application should need.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * The priority Gedmo's own `onFlush` listeners end up with.
     *
     * Gedmo declares none, so whatever registers them — StofDoctrineExtensionsBundle, a
     * `doctrine.event_listener` tag, an application compiler pass — lands on Symfony's default.
     * Every default in this tree that has to be above or below Gedmo is expressed against this
     * number rather than repeating it, so the reason is readable and a test can assert it.
     */
    public const int GEDMO_LISTENER_PRIORITY = 0;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('audit_trail');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Global kill-switch. When false the Doctrine listener and the manual logger become no-ops; every service stays registered so decoration and tests keep working.')
                ->end()
                ->scalarNode('table_name')
                    ->defaultValue('audit_logs')
                    ->cannotBeEmpty()
                    ->info('Name of the single table holding the trail. Renaming it rewrites the entity mapping at runtime; the change must be reflected in a migration.')
                ->end()
                ->scalarNode('mask')
                    ->defaultValue('********')
                    ->info('Sentinel written instead of the value of a property marked #[AuditMasked]. A per-property mask overrides it.')
                ->end()
                ->integerNode('listener_priority')
                    ->defaultValue(0)
                    ->info('Priority of the onFlush listener. Raise it to run before listeners that rewrite change sets (Gedmo Translatable), lower it to run after listeners that stamp fields (Timestampable, Blameable).')
                ->end()
                ->append($this->captureNode())
                ->append($this->bridgesNode())
            ->end();

        return $treeBuilder;
    }

    private function captureNode(): NodeDefinition
    {
        $builder = new TreeBuilder('capture');

        $builder->getRootNode()
            ->info('What the automatic Doctrine capture puts in an entry.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('state_on_create')
                    ->defaultTrue()
                    ->info('Record the full state of a created entity, so a creation reads on its own instead of pointing at a row that may later change.')
                ->end()
                ->booleanNode('state_on_delete')
                    ->defaultTrue()
                    ->info('Record the full state of a deleted entity. Disabling this leaves deletions unreadable once the row is gone.')
                ->end()
                ->booleanNode('suppress_cascade_children')
                    ->defaultTrue()
                    ->info('Skip an auditable child whose owning root is deleted in the same flush, so removing one aggregate does not produce a burst of entries nobody reads.')
                ->end()
            ->end();

        return $builder->getRootNode();
    }

    private function bridgesNode(): NodeDefinition
    {
        $builder = new TreeBuilder('bridges');

        $builder->getRootNode()
            ->info('Optional integrations. Each one is auto-detected from the installed packages and can be forced on or off.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('gedmo')
                    ->info('Integration with gedmo/doctrine-extensions.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultNull()
                            ->info('Null auto-detects gedmo/doctrine-extensions. Set explicitly to force the bridge on or off.')
                        ->end()
                        ->booleanNode('translatable')
                            ->defaultTrue()
                            ->info('Audit translated fields, which Translatable moves out of the entity change set before a plain listener can see them. False removes the translation listener and stops trimming translated fields out of the entity own update entry.')
                        ->end()
                        ->booleanNode('soft_deleteable')
                            ->defaultTrue()
                            ->info('Record a soft delete as a deletion rather than as an update of the deletedAt field. False leaves every logical delete an ordinary update.')
                        ->end()
                        ->integerNode('listener_priority')
                            ->defaultValue(self::GEDMO_LISTENER_PRIORITY + 1)
                            ->info('Priority of the translation onFlush listener. It must stay strictly above Gedmo TranslatableListener, which reverts translatable fields out of the change set: below it, translation auditing silently records nothing. Gedmo registers its own listeners at 0, hence the default of 1.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('api_platform')
                    ->info('Read-only API Platform resource exposing the trail.')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['max_items_per_page'] < $v['items_per_page'])
                        ->thenInvalid('audit_trail.bridges.api_platform.max_items_per_page must be greater than or equal to items_per_page.')
                    ->end()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultNull()
                            ->info('Null auto-detects api-platform/core. Set explicitly to force the bridge on or off.')
                        ->end()
                        ->scalarNode('route_prefix')
                            ->defaultValue('/audit_logs')
                            ->cannotBeEmpty()
                            ->info('Path the audit feed is mounted on.')
                        ->end()
                        ->integerNode('items_per_page')
                            ->defaultValue(50)
                            ->min(1)
                            ->info('Default page size of the feed.')
                        ->end()
                        ->integerNode('max_items_per_page')
                            ->defaultValue(200)
                            ->min(1)
                            ->info('Ceiling a client may ask for, so a public feed cannot be turned into a full table export.')
                        ->end()
                        ->scalarNode('security')
                            ->defaultNull()
                            ->info('Access-control expression applied to the feed, for example "is_granted(\'ROLE_ADMIN\')". Null emits no security attribute at all: the application is then responsible for protecting the route.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $builder->getRootNode();
    }
}
