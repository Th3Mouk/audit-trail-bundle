<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\DependencyInjection;

use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\Events;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Th3Mouk\AuditTrail\Actor\SecurityTokenActorResolver;
use Th3Mouk\AuditTrail\Bridge\Gedmo\SoftDeleteableActionResolver;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationAuditListener;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationFieldExclusion;
use Th3Mouk\AuditTrail\EventListener\AuditLogListener;

/**
 * Wires the bundle and nothing else.
 *
 * Optional integrations are decided here rather than inside the service files: an
 * application that has neither api-platform/core, nor gedmo/doctrine-extensions, nor
 * symfony/security-bundle installed gets a container that never mentions them.
 */
final class AuditTrailExtension extends Extension implements PrependExtensionInterface
{
    private const string MAPPING_NAME = 'AuditTrail';
    private const string ENTITY_NAMESPACE = 'Th3Mouk\AuditTrail\Entity';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $gedmoEnabled = $config['bridges']['gedmo']['enabled'] ?? class_exists(TranslatableListener::class);
        $apiPlatformEnabled = $config['bridges']['api_platform']['enabled'] ?? class_exists(Operation::class);

        $container->setParameter('audit_trail.enabled', $config['enabled']);
        $container->setParameter('audit_trail.table_name', $config['table_name']);
        $container->setParameter('audit_trail.mask', $config['mask']);
        $container->setParameter('audit_trail.listener_priority', $config['listener_priority']);
        $container->setParameter('audit_trail.capture.state_on_create', $config['capture']['state_on_create']);
        $container->setParameter('audit_trail.capture.state_on_delete', $config['capture']['state_on_delete']);
        $container->setParameter('audit_trail.capture.suppress_cascade_children', $config['capture']['suppress_cascade_children']);
        $container->setParameter('audit_trail.bridges.gedmo.enabled', $gedmoEnabled);
        $container->setParameter('audit_trail.bridges.gedmo.translatable', $config['bridges']['gedmo']['translatable']);
        $container->setParameter('audit_trail.bridges.gedmo.soft_deleteable', $config['bridges']['gedmo']['soft_deleteable']);
        $container->setParameter('audit_trail.bridges.gedmo.listener_priority', $config['bridges']['gedmo']['listener_priority']);
        $container->setParameter('audit_trail.bridges.api_platform.enabled', $apiPlatformEnabled);
        $container->setParameter('audit_trail.bridges.api_platform.route_prefix', $config['bridges']['api_platform']['route_prefix']);
        $container->setParameter('audit_trail.bridges.api_platform.items_per_page', $config['bridges']['api_platform']['items_per_page']);
        $container->setParameter('audit_trail.bridges.api_platform.max_items_per_page', $config['bridges']['api_platform']['max_items_per_page']);
        $container->setParameter('audit_trail.bridges.api_platform.security', $config['bridges']['api_platform']['security']);

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('services.php');

        $this->applyListenerPriority($container, $config['listener_priority']);
        $this->registerSecurityActorResolver($container);

        if ($gedmoEnabled) {
            $loader->load('bridge_gedmo.php');
            $this->applyGedmoBridgeOptions($container, $config['bridges']['gedmo']);
        }

        // The API Platform feed is assembled by RegisterAuditFeedPass, which inspects the
        // compiled container to decide whether the bridge applies. Nothing to load here.
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'entity_managers' => [
                    $this->targetEntityManagerName($container) => [
                        'mappings' => [
                            self::MAPPING_NAME => [
                                'type' => 'attribute',
                                'is_bundle' => false,
                                'dir' => \dirname(__DIR__).'/Entity',
                                'prefix' => self::ENTITY_NAMESPACE,
                                'alias' => self::MAPPING_NAME,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function applyListenerPriority(ContainerBuilder $container, int $priority): void
    {
        $container->getDefinition(AuditLogListener::class)
            ->clearTag('doctrine.event_listener')
            ->addTag('doctrine.event_listener', ['event' => Events::onFlush, 'priority' => $priority]);
    }

    /**
     * Each half of the Gedmo bridge is registered only when its own option says so.
     *
     * `bridge_gedmo.php` cannot make that decision: a service file has no access to the
     * processed configuration, and Doctrine's listener pass reads the `priority` tag attribute
     * as a plain integer, so a `%parameter%` placeholder there would not survive. Both are
     * therefore expressed as removals and a tag rewrite here, exactly like
     * `applyListenerPriority()` does for capture itself.
     *
     * @param array{translatable: bool, soft_deleteable: bool, listener_priority: int} $gedmo
     */
    private function applyGedmoBridgeOptions(ContainerBuilder $container, array $gedmo): void
    {
        if ($gedmo['translatable']) {
            $container->getDefinition(TranslationAuditListener::class)
                ->clearTag('doctrine.event_listener')
                ->addTag('doctrine.event_listener', ['event' => Events::onFlush, 'priority' => $gedmo['listener_priority']]);
        } else {
            $container->removeDefinition(TranslationAuditListener::class);
            $container->removeDefinition(TranslationFieldExclusion::class);
        }

        if (!$gedmo['soft_deleteable']) {
            $container->removeDefinition(SoftDeleteableActionResolver::class);
        }
    }

    private function registerSecurityActorResolver(ContainerBuilder $container): void
    {
        if (!interface_exists(TokenStorageInterface::class) || !$container->hasExtension('security')) {
            return;
        }

        $container->register(SecurityTokenActorResolver::class)
            ->setArguments([new Reference('security.token_storage')])
            ->addTag('audit_trail.actor_resolver', ['priority' => -100]);
    }

    private function targetEntityManagerName(ContainerBuilder $container): string
    {
        $declaredManagers = [];

        foreach ($container->getExtensionConfig('doctrine') as $doctrineConfig) {
            if (!\is_array($doctrineConfig) || !\is_array($doctrineConfig['orm'] ?? null)) {
                continue;
            }

            $orm = $doctrineConfig['orm'];

            if (\is_string($orm['default_entity_manager'] ?? null)) {
                return $orm['default_entity_manager'];
            }

            if (\is_array($orm['entity_managers'] ?? null)) {
                $declaredManagers = [...$declaredManagers, ...array_keys($orm['entity_managers'])];
            }
        }

        $firstDeclaredManager = $declaredManagers[0] ?? null;

        return \is_string($firstDeclaredManager) ? $firstDeclaredManager : 'default';
    }
}
