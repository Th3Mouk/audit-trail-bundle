<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Gedmo\Blameable\BlameableListener;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeKernel;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\AuditedTranslatablePage;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\StampedPage;

/**
 * The two neighbours the audit listener has to sit between.
 *
 * Above it, listeners that stamp fields: their values must already be in the change set.
 * Below it, listeners that rewrite change sets: their rewriting must not reach capture. Both
 * claims are checked against the change set capture actually recorded, not against the
 * configured priorities.
 */
final class ListenerOrderingTest extends GedmoBridgeTestCase
{
    public function testCaptureSeesTheValueGedmoTakesBackOutOfTheChangeSetAfterwards(): void
    {
        $page = new AuditedTranslatablePage('English title');
        $this->given($page);
        $identifier = $page->getId();

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $entry = $this->assertOneTranslationEntry(AuditedTranslatablePage::class, 'fr');
        $this->assertFieldRecorded($entry, 'title', [
            'locale' => 'fr',
            'before' => 'English title',
            'after' => 'Titre français',
        ]);

        $this->em()->clear();
        $reloaded = $this->em()->find(AuditedTranslatablePage::class, $identifier);

        self::assertInstanceOf(AuditedTranslatablePage::class, $reloaded);
        self::assertSame(
            'English title',
            $reloaded->getTitle(),
            'Gedmo should have taken the translated field back out of the change set, leaving the entity own column untouched.',
        );
    }

    public function testAFieldStampedByGedmoIsPartOfTheRecordedDiff(): void
    {
        $page = new StampedPage('Draft');
        $this->given($page);

        self::assertSame(GedmoBridgeKernel::INITIAL_USER, $page->getUpdatedBy());

        $this->blameableListener()->setUserValue('editor');
        $page->rename('Reviewed');
        $this->save($page);

        $entry = $this->assertOneEntry(AuditAction::Update, StampedPage::class);
        $this->assertFieldChanged($entry, 'title', 'Draft', 'Reviewed');
        $this->assertFieldChanged($entry, 'updatedBy', GedmoBridgeKernel::INITIAL_USER, 'editor');
    }

    private function blameableListener(): BlameableListener
    {
        $listener = $this->service(BlameableListener::class);
        \assert($listener instanceof BlameableListener);

        return $listener;
    }
}
