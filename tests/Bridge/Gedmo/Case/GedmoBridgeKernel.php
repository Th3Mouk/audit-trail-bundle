<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case;

use Gedmo\Blameable\BlameableListener;
use Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\GedmoKernel;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\TestKernel;

/**
 * GedmoKernel plus what the shared fixtures cannot express.
 *
 * Two differences, both deliberate:
 *
 * - the bridge's own fixtures are mapped alongside the shared ones, which is how a translated
 *   field can carry `#[AuditMasked]` / `#[NotAuditable]` and a soft-deleted row can be
 *   restored;
 * - a *stamping* listener (Blameable) is registered **above** the audit listener while the
 *   *rewriting* listeners (Translatable, SoftDeleteable) stay at Gedmo's real default of 0,
 *   below it. That is the ordering the capture contract asks for — after stampers, before
 *   rewriters — and it cannot be arranged when every Gedmo listener shares one priority.
 *
 * Only capture is moved. The Gedmo bridge's own translation listener runs at whatever
 * `bridges.gedmo.listener_priority` defaults to, against rewriters at 0, so the suite is
 * asserting the configuration a fresh installation gets.
 */
final class GedmoBridgeKernel extends TestKernel
{
    public const int STAMPING_LISTENER_PRIORITY = 128;

    public const string INITIAL_USER = 'author';

    public static function isSupported(): bool
    {
        return GedmoKernel::isSupported() && class_exists(BlameableListener::class);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function auditTrailConfig(): array
    {
        return ['listener_priority' => GedmoKernel::CAPTURE_LISTENER_PRIORITY, ...parent::auditTrailConfig()];
    }

    #[\Override]
    protected function entityMappings(): array
    {
        return [
            ...parent::entityMappings(),
            'AuditTrailGedmoFixtures' => [
                'type' => 'attribute',
                'dir' => \dirname(__DIR__, 3).'/Fixtures/Gedmo/Entity',
                'prefix' => 'Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity',
                'is_bundle' => false,
            ],
            'AuditTrailGedmoBridgeFixtures' => [
                'type' => 'attribute',
                'dir' => \dirname(__DIR__).'/Fixture',
                'prefix' => 'Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture',
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
        $translatable->addMethodCall('setDefaultLocale', [GedmoKernel::DEFAULT_LOCALE]);
        $translatable->addMethodCall('setTranslatableLocale', [GedmoKernel::DEFAULT_LOCALE]);
        $translatable->addMethodCall('setTranslationFallback', [true]);
        self::listenTo($translatable, GedmoKernel::GEDMO_LISTENER_PRIORITY, 'postLoad', 'postPersist', 'preFlush', 'onFlush', 'loadClassMetadata');

        $softDeleteable = $container->register(SoftDeleteableListener::class, SoftDeleteableListener::class);
        $softDeleteable->setPublic(true);
        self::listenTo($softDeleteable, GedmoKernel::GEDMO_LISTENER_PRIORITY, 'loadClassMetadata', 'onFlush', 'postFlush');

        $blameable = $container->register(BlameableListener::class, BlameableListener::class);
        $blameable->setPublic(true);
        $blameable->addMethodCall('setUserValue', [self::INITIAL_USER]);
        self::listenTo($blameable, self::STAMPING_LISTENER_PRIORITY, 'loadClassMetadata', 'prePersist', 'onFlush');
    }

    private static function listenTo(Definition $listener, int $priority, string ...$events): void
    {
        foreach ($events as $event) {
            $listener->addTag('doctrine.event_listener', [
                'event' => $event,
                'priority' => $priority,
            ]);
        }
    }
}
