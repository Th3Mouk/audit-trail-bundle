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
                ->scalarNode('entity_manager')
                    ->defaultNull()
                    ->info('Entity manager that owns the trail. Null uses the default one. Only this manager is audited: an entry must join the transaction of the change it describes, and that is only possible inside the manager that produced it.')
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
                    ->defaultFalse()
                    ->info('Skip an auditable child whose owning root is deleted in the same flush, so removing one aggregate does not produce a burst of entries nobody reads. Off by default: the rule follows every to-one association rather than proving the deletion was really a cascade, so two deliberate deletions in one flush can be collapsed into one entry. Turn it on when the noise is real and you accept that trade.')
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
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => true === $v['enabled'] && 1 !== self::declaredAccessModes($v['access']))
                        ->thenInvalid(
                            'audit_trail.bridges.api_platform.access requires exactly one of "grants", "expression" or '
                            .'"public". The feed exposes actors, IP addresses and field-level diffs, so the bundle will '
                            .'not guess who may read it: list the grants a reader needs (for example '
                            .'grants: [{ attribute: view, subject: audit-logs }]), give a raw API Platform expression, '
                            .'or opt out loudly with public: true.',
                        )
                    ->end()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Off by default, on purpose: unlike the Gedmo bridge this one publishes data over HTTP, so installing api-platform/core must never be enough to expose the trail. Turn it on explicitly, and declare `access` when you do.')
                        ->end()
                        ->scalarNode('route_prefix')
                            ->defaultValue('/audit-logs')
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
                        ->arrayNode('access')
                            ->addDefaultsIfNotSet()
                            ->info('Who may read the trail. Exactly one of "grants", "expression" or "public" is required once the bridge is on.')
                            ->children()
                                ->arrayNode('grants')
                                    ->info('Access is granted when any listed check passes. A plain string is the attribute alone ("ROLE_AUDITOR", "show audit"); the mapping form adds a subject, which permission systems anchored on a resource need ({ attribute: view, subject: audit-logs }). Anything a Symfony voter can decide is expressible here — write the voter, name its attribute.')
                                    ->example(['show audit', ['attribute' => 'view', 'subject' => 'audit-logs']])
                                    ->arrayPrototype()
                                        ->beforeNormalization()
                                            ->ifString()
                                            ->then(static fn (string $attribute): array => ['attribute' => $attribute])
                                        ->end()
                                        ->children()
                                            ->scalarNode('attribute')->isRequired()->cannotBeEmpty()->end()
                                            ->scalarNode('subject')->defaultNull()->info('Passed to is_granted() as the second argument.')->end()
                                        ->end()
                                    ->end()
                                ->end()
                                ->scalarNode('expression')
                                    ->defaultNull()
                                    ->info('A raw API Platform security expression, for decisions "grants" cannot express: "is_granted(\'show audit\') and user.isInternal()". Requires symfony/expression-language.')
                                ->end()
                                ->booleanNode('public')
                                    ->defaultFalse()
                                    ->info('Serve the trail with no access control of its own. Only meaningful when something else already protects the route — a firewall, an access_control rule, a gateway. Say it explicitly so it is a decision rather than an oversight.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $builder->getRootNode();
    }

    /**
     * Turns the declared grants into an API Platform security expression.
     *
     * Any one grant is enough, so the checks are joined with `or`. Subjects and attributes are
     * host-supplied strings that end up inside expression literals, hence the escaping.
     *
     * @param array{grants: list<array{attribute: string, subject: string|null}>, expression: string|null, public: bool} $access
     */
    public static function accessExpression(array $access): ?string
    {
        if ($access['public']) {
            return null;
        }

        if (null !== $access['expression'] && '' !== $access['expression']) {
            return $access['expression'];
        }

        $checks = array_map(
            static function (array $grant): string {
                $attribute = self::escapeExpressionString($grant['attribute']);

                return null === $grant['subject'] || '' === $grant['subject']
                    ? \sprintf("is_granted('%s')", $attribute)
                    : \sprintf("is_granted('%s', '%s')", $attribute, self::escapeExpressionString($grant['subject']));
            },
            $access['grants'],
        );

        return [] === $checks ? null : implode(' or ', $checks);
    }

    /**
     * @param array{grants: list<array{attribute: string, subject: string|null}>, expression: string|null, public: bool} $access
     */
    private static function declaredAccessModes(array $access): int
    {
        return (int) ([] !== $access['grants'])
            + (int) (null !== $access['expression'] && '' !== $access['expression'])
            + (int) $access['public'];
    }

    private static function escapeExpressionString(string $value): string
    {
        return addcslashes($value, "'\\");
    }
}
