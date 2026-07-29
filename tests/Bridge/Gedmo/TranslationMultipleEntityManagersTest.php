<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationAuditListener;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\TwoEntityManagersGedmoKernel;
use Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity\TranslatablePage;

/**
 * One entity manager owns the trail — including for translations.
 *
 * The translation listener is a second `onFlush` subscriber, and Doctrine registers it on every
 * connection just like the first. Without its own identity check it would record a translation
 * flushed by another manager into storage bound to the audited one: an entry enlisted in a
 * different UnitOfWork from the change it describes, written outside the transaction that would
 * have rolled it back.
 */
final class TranslationMultipleEntityManagersTest extends GedmoBridgeTestCase
{
    #[\Override]
    protected static function kernelUnderTest(): string
    {
        return TwoEntityManagersGedmoKernel::class;
    }

    public function testATranslationFlushedThroughTheAuditedManagerIsStillRecorded(): void
    {
        $page = new TranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $this->assertOneTranslationEntry(TranslatablePage::class, 'fr');
    }

    /**
     * The guard, proven where it acts: a foreign manager's unit of work is not even read.
     *
     * Asserting "nothing was recorded" after flushing through the other manager would pass for
     * reasons that have nothing to do with the guard — translation capture can come up empty on its
     * own — so the assertion is on the one thing only the guard can cause.
     */
    public function testAForeignManagersFlushIsNotEvenInspected(): void
    {
        $foreign = $this->createMock(EntityManagerInterface::class);
        $foreign->expects(self::never())->method('getUnitOfWork');

        $this->translationAuditListener($this->em())->onFlush(new OnFlushEventArgs($foreign));

        self::assertSame([], $this->recordedEntries());
        self::assertFalse($this->flushState()->isFlushing(), 'A flush the listener skips is never marked.');
    }

    /**
     * The mirror image: the audited manager's flush *is* inspected, so the assertion above is about
     * which manager it was, not about the listener being inert.
     */
    public function testTheAuditedManagersFlushIsInspected(): void
    {
        $audited = $this->createMock(EntityManagerInterface::class);
        $audited->expects(self::once())->method('getUnitOfWork')->willReturn($this->em()->getUnitOfWork());

        $this->translationAuditListener($audited)->onFlush(new OnFlushEventArgs($audited));
    }

    public function testTheListenerHoldsTheAuditedManager(): void
    {
        $listener = self::getContainer()->get(TranslationAuditListener::class);
        self::assertInstanceOf(TranslationAuditListener::class, $listener);

        $held = new \ReflectionProperty(TranslationAuditListener::class, 'entityManager')->getValue($listener);

        self::assertSame(
            $this->managerNamed(TwoEntityManagersGedmoKernel::AUDITED_MANAGER),
            $held,
            'Autowiring hands out the default manager; the extension has to bind the audited one.',
        );
    }

    private function managerNamed(string $name): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $manager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
