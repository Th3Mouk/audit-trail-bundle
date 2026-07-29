<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

use Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The Doctrine-only setup plus Gedmo listeners, wired by hand.
 *
 * StofDoctrineExtensionsBundle is deliberately absent: the bundle's Gedmo bridge must work
 * against the library, not against a particular integration bundle, and depending on one
 * here would let a coupling to it slip in unnoticed.
 *
 * Gedmo's listeners are registered at **0** — the priority they really get in an application,
 * since Gedmo declares none and Symfony's default is 0. Registering them lower would let the
 * suite pass on an ordering no installation has, which is exactly how a broken shipped default
 * survives a green build. Everything the bridge needs at that ordering has to come from its
 * own defaults.
 *
 * Capture itself is the one thing an application still has to place, because a single priority
 * cannot be both above Gedmo's rewriters and below its stampers while all of them share 0.
 * `CAPTURE_LISTENER_PRIORITY` is that placement, and it is the arrangement `docs/bridges/gedmo.md`
 * prescribes — not a workaround for a bridge default.
 */
final class GedmoKernel extends TestKernel
{
    public const int GEDMO_LISTENER_PRIORITY = 0;

    public const int CAPTURE_LISTENER_PRIORITY = 64;

    public const string DEFAULT_LOCALE = 'en';

    public static function isSupported(): bool
    {
        return class_exists(TranslatableListener::class);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function auditTrailConfig(): array
    {
        return ['listener_priority' => self::CAPTURE_LISTENER_PRIORITY, ...parent::auditTrailConfig()];
    }

    #[\Override]
    protected function entityMappings(): array
    {
        return [
            ...parent::entityMappings(),
            'AuditTrailGedmoFixtures' => [
                'type' => 'attribute',
                'dir' => \dirname(__DIR__).'/Gedmo/Entity',
                'prefix' => 'Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity',
                'is_bundle' => false,
            ],
        ];
    }

    #[\Override]
    protected function doctrineConfig(): array
    {
        $config = parent::doctrineConfig();
        $config['orm']['filters'] = [
            'soft_deleteable' => [
                'class' => SoftDeleteableFilter::class,
                'enabled' => true,
            ],
        ];

        return $config;
    }

    #[\Override]
    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);

        $translatable = $container->register(TranslatableListener::class, TranslatableListener::class);
        $translatable->setPublic(true);
        $translatable->addMethodCall('setDefaultLocale', [self::DEFAULT_LOCALE]);
        $translatable->addMethodCall('setTranslatableLocale', [self::DEFAULT_LOCALE]);
        $translatable->addMethodCall('setTranslationFallback', [true]);
        self::listenTo($translatable, 'postLoad', 'postPersist', 'preFlush', 'onFlush', 'loadClassMetadata');

        $softDeleteable = $container->register(SoftDeleteableListener::class, SoftDeleteableListener::class);
        $softDeleteable->setPublic(true);
        self::listenTo($softDeleteable, 'loadClassMetadata', 'onFlush', 'postFlush');
    }

    /**
     * Only the events the installed Gedmo actually implements are registered.
     *
     * The supported range spans versions that gained listener methods along the way —
     * `SoftDeleteableListener::postFlush()` does not exist on the oldest one — and Doctrine calls a
     * tagged method without checking, so tagging blindly turns the lowest cell of the dependency
     * matrix into an undefined-method error.
     */
    private static function listenTo(Definition $listener, string ...$events): void
    {
        $class = $listener->getClass() ?? '';

        foreach ($events as $event) {
            if (!method_exists($class, $event)) {
                continue;
            }

            $listener->addTag('doctrine.event_listener', [
                'event' => $event,
                'priority' => self::GEDMO_LISTENER_PRIORITY,
            ]);
        }
    }
}
