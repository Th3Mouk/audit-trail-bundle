<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\DependencyInjection;

use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\Events;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Th3Mouk\AuditTrail\Bridge\Gedmo\SoftDeleteableActionResolver;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationAuditListener;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationFieldExclusion;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Capture\Gate\CascadeSuppressionGate;
use Th3Mouk\AuditTrail\Capture\Value\EntityReferenceValueSerializer;
use Th3Mouk\AuditTrail\EventListener\AuditLogListener;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Metadata\AuditTypeWarmer;
use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;
use Th3Mouk\AuditTrail\Storage\DoctrineAuditStorage;

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

    /**
     * Services holding an `EntityManagerInterface`, which must be the audited one.
     */
    private const array ENTITY_MANAGER_CONSUMERS = [
        AuditableResolver::class,
        FieldPolicyResolver::class,
        EntityIdResolver::class,
        EntityReferenceValueSerializer::class,
        CascadeSuppressionGate::class,
        DoctrineAuditStorage::class,
        AuditTypeWarmer::class,
        TranslationAuditListener::class,
    ];
    private const string ENTITY_NAMESPACE = 'Th3Mouk\AuditTrail\Entity';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        // The two bridges are enabled differently on purpose. Gedmo only changes how capture reads a
        // change set, so auto-detecting it is harmless and convenient. The API Platform bridge
        // publishes the trail over HTTP, so it stays off until the application asks for it by name —
        // installing a package must never be what exposes an audit log.
        $gedmoEnabled = $config['bridges']['gedmo']['enabled'] ?? class_exists(TranslatableListener::class);
        $apiPlatformEnabled = $config['bridges']['api_platform']['enabled'] && class_exists(Operation::class);

        $entityManagerName = $config['entity_manager'] ?? $this->targetEntityManagerName($container);

        $container->setParameter('audit_trail.enabled', $config['enabled']);
        $container->setParameter('audit_trail.entity_manager', $entityManagerName);
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
        $container->setParameter(
            'audit_trail.bridges.api_platform.security',
            $this->auditFeedSecurity($config['bridges']['api_platform'], $apiPlatformEnabled),
        );

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('services.php');

        $this->applyListenerPriority($container, $config['listener_priority']);

        if ($gedmoEnabled) {
            $loader->load('bridge_gedmo.php');
            $this->applyGedmoBridgeOptions($container, $config['bridges']['gedmo']);
        }

        // Last, and not by taste: a bridge's services do not exist until its file is loaded, and the
        // binding below skips what it cannot find. Bound any earlier, the Gedmo listener would
        // silently keep the default manager — the exact bug this method exists to prevent.
        $this->bindAuditedEntityManager($container, $entityManagerName);

        // Two things are deliberately absent from this method: the API Platform feed and the
        // default security actor resolver. Both are assembled by a compiler pass —
        // RegisterAuditFeedPass and RegisterSecurityActorResolverPass, registered from
        // AuditTrailBundle::build() — because both decisions need the real, fully-merged
        // container to answer honestly, which load() never receives.
    }

    /**
     * Points every collaborator that touches Doctrine at the one manager that owns the trail.
     *
     * Autowiring would otherwise hand them the default manager whatever `entity_manager` says, and
     * the listener would then read a change set from one UnitOfWork while the storage enlisted its
     * entry in another — a row written outside the transaction it belongs to.
     */
    private function bindAuditedEntityManager(ContainerBuilder $container, string $entityManagerName): void
    {
        $reference = new Reference(\sprintf('doctrine.orm.%s_entity_manager', $entityManagerName));

        foreach (self::ENTITY_MANAGER_CONSUMERS as $serviceId) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $container->getDefinition($serviceId)->setArgument('$entityManager', $reference);
        }

        $container->getDefinition(AuditLogListener::class)
            ->setArgument('$auditedEntityManager', $reference);
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'entity_managers' => [
                    $this->mappingEntityManagerName($container) => [
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

    /**
     * Resolves the feed's access control, and refuses to compile rather than to serve it unguarded.
     *
     * Every mode ends up as API Platform's own `security` attribute, which is evaluated by the
     * expression language — so a missing symfony/expression-language is caught here, at build time,
     * with the package name in the message. Left to runtime it surfaces as a 500 on the first
     * request, which is a far worse way to learn that the trail is unreadable.
     *
     * @param array{enabled: bool, access: array{grants: list<array{attribute: string, subject: string|null}>, expression: string|null, public: bool}} $apiPlatform
     */
    private function auditFeedSecurity(array $apiPlatform, bool $enabled): ?string
    {
        $expression = Configuration::accessExpression($apiPlatform['access']);

        if (!$enabled || null === $expression) {
            return $expression;
        }

        if (!class_exists(ExpressionLanguage::class)) {
            throw new InvalidConfigurationException(
                'The audit feed\'s access control is an API Platform security expression, which needs the '
                .'expression language: run "composer require symfony/expression-language". Alternatively set '
                .'audit_trail.bridges.api_platform.access.public to true and protect the route yourself.',
            );
        }

        return $expression;
    }

    /**
     * `prepend()` runs before `load()`, so the processed configuration is not available yet: the
     * declared `entity_manager` is read straight from the raw configuration here.
     *
     * Reading it means merging it the way Symfony will. A bundle's configuration arrives in layers —
     * `config/packages/audit_trail.php` then `config/packages/{env}/audit_trail.php` — and the
     * Config component merges them left to right, so for a scalar the **last** declaration is the
     * effective one. Taking the first would install the mapping on one manager while `load()`, which
     * reads the merged configuration, binds every service to another: the entity would be mapped
     * where nothing writes it.
     */
    private function mappingEntityManagerName(ContainerBuilder $container): string
    {
        $declared = self::lastDeclaredValue($container->getExtensionConfig('audit_trail'), 'entity_manager');

        return \is_string($declared) ? $declared : $this->targetEntityManagerName($container);
    }

    /**
     * The last layer that mentions a key at all, whatever it says — `null` included.
     *
     * A key present with a null value is not the same thing as a key nobody wrote: it is how an
     * environment undoes what the common configuration declared, and the merged configuration
     * honours it. Skipping nulls would keep the earlier declaration for the mapping while every
     * service follows the merged one.
     *
     * @param array<array-key, mixed> $configs
     */
    private static function lastDeclaredValue(array $configs, string $key): mixed
    {
        $declared = null;

        foreach ($configs as $config) {
            if (\is_array($config) && \array_key_exists($key, $config)) {
                $declared = $config[$key];
            }
        }

        return $declared;
    }

    /**
     * Doctrine's own configuration is read with the same rule, and for the same reason: an
     * environment file may name a different `default_entity_manager`, and the last one wins.
     */
    private function targetEntityManagerName(ContainerBuilder $container): string
    {
        $declaredDefaults = [];
        $declaredManagers = [];

        foreach ($container->getExtensionConfig('doctrine') as $doctrineConfig) {
            if (!\is_array($doctrineConfig) || !\is_array($doctrineConfig['orm'] ?? null)) {
                continue;
            }

            $orm = $doctrineConfig['orm'];

            $declaredDefaults[] = $orm;

            if (\is_array($orm['entity_managers'] ?? null)) {
                // Names, unlike scalars, are unioned rather than replaced when Symfony merges the
                // layers, and the first declared manager is Doctrine's own fallback default.
                $declaredManagers = [...$declaredManagers, ...array_keys($orm['entity_managers'])];
            }
        }

        $defaultManager = self::lastDeclaredValue($declaredDefaults, 'default_entity_manager');

        if (\is_string($defaultManager)) {
            return $defaultManager;
        }

        $firstDeclaredManager = $declaredManagers[0] ?? null;

        return \is_string($firstDeclaredManager) ? $firstDeclaredManager : 'default';
    }
}
