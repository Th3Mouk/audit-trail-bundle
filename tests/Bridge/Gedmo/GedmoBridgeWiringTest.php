<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Doctrine\ORM\Events;
use Gedmo\Translatable\TranslatableListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Th3Mouk\AuditTrail\Bridge\Gedmo\SoftDeleteableActionResolver;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationAuditListener;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationFieldExclusion;
use Th3Mouk\AuditTrail\DependencyInjection\AuditTrailExtension;
use Th3Mouk\AuditTrail\DependencyInjection\Configuration;

/**
 * What the container looks like before anything runs.
 *
 * The two claims here are the ones a behavioural test cannot make. A listener priority that sits
 * on the wrong side of Gedmo's produces no error and no entry, so the shipped default has to be
 * asserted as a number; and an option that switches a piece off can only be checked by looking
 * for the piece.
 *
 * Read from a bare `ContainerBuilder` rather than from a booted kernel: definitions and their
 * tags are gone by the time a container is compiled, and the priority is a compile-time fact.
 */
#[CoversClass(AuditTrailExtension::class)]
#[CoversClass(Configuration::class)]
final class GedmoBridgeWiringTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TranslatableListener::class)) {
            self::markTestSkipped('gedmo/doctrine-extensions is not installed: the bridge is never loaded.');
        }

        parent::setUp();
    }

    /**
     * The regression guard for the defect this option exists to fix.
     *
     * Gedmo's `TranslatableListener` reverts translatable fields out of the change set the
     * translation listener reads. Below Gedmo's priority the bridge sees an already-rewritten
     * change set, records nothing, and says nothing about it — a green suite and an empty trail.
     */
    public function testTheTranslationListenerDefaultsStrictlyAboveGedmoOwnPriority(): void
    {
        $priority = $this->translationListenerPriority();

        self::assertGreaterThan(
            Configuration::GEDMO_LISTENER_PRIORITY,
            $priority,
            'The shipped priority must run the translation listener BEFORE Gedmo, or translation auditing records nothing at all.',
        );
    }

    public function testTheTranslationListenerPriorityIsTheConfiguredOne(): void
    {
        self::assertSame(512, $this->translationListenerPriority(['bridges' => ['gedmo' => ['listener_priority' => 512]]]));
    }

    public function testBothPiecesAreWiredByDefault(): void
    {
        $container = $this->load();

        self::assertTrue($container->hasDefinition(TranslationAuditListener::class));
        self::assertTrue($container->hasDefinition(TranslationFieldExclusion::class));
        self::assertTrue($container->hasDefinition(SoftDeleteableActionResolver::class));
    }

    public function testTheSoftDeleteableActionResolverReachesCaptureThroughTheActionResolverTag(): void
    {
        self::assertNotSame(
            [],
            $this->load()->getDefinition(SoftDeleteableActionResolver::class)->getTag('audit_trail.action_resolver'),
            'Without the tag the resolver is a service nothing consults, which is what made the soft-delete mapping inert.',
        );
    }

    public function testTheTranslationExclusionReachesCaptureThroughTheFieldExclusionTag(): void
    {
        self::assertNotSame(
            [],
            $this->load()->getDefinition(TranslationFieldExclusion::class)->getTag('audit_trail.field_exclusion'),
        );
    }

    public function testTranslatableFalseRemovesTheListenerAndItsFieldExclusion(): void
    {
        $container = $this->load(['bridges' => ['gedmo' => ['translatable' => false]]]);

        self::assertFalse($container->hasDefinition(TranslationAuditListener::class));
        self::assertFalse($container->hasDefinition(TranslationFieldExclusion::class));
        self::assertTrue($container->hasDefinition(SoftDeleteableActionResolver::class), 'The two halves switch off independently.');
    }

    public function testSoftDeleteableFalseRemovesTheActionResolver(): void
    {
        $container = $this->load(['bridges' => ['gedmo' => ['soft_deleteable' => false]]]);

        self::assertFalse($container->hasDefinition(SoftDeleteableActionResolver::class));
        self::assertTrue($container->hasDefinition(TranslationAuditListener::class), 'The two halves switch off independently.');
    }

    public function testTheWholeBridgeCanBeSwitchedOff(): void
    {
        $container = $this->load(['bridges' => ['gedmo' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(TranslationAuditListener::class));
        self::assertFalse($container->hasDefinition(TranslationFieldExclusion::class));
        self::assertFalse($container->hasDefinition(SoftDeleteableActionResolver::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function translationListenerPriority(array $config = []): int
    {
        $tags = $this->load($config)->getDefinition(TranslationAuditListener::class)->getTag('doctrine.event_listener');

        $priorities = array_values(array_map(
            static fn (array $tag): int => (int) ($tag['priority'] ?? 0),
            array_filter($tags, static fn (array $tag): bool => Events::onFlush === ($tag['event'] ?? null)),
        ));

        self::assertCount(1, $priorities, 'The translation listener must carry exactly one onFlush tag.');

        return $priorities[0];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new AuditTrailExtension()->load([$config], $container);

        return $container;
    }
}
