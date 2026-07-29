<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\AuditedTranslatablePage;

/**
 * The entity's *own* entry, when the write went to a translation row instead.
 *
 * Capture has to read the change set before Gedmo reverts it, which means it sees translated
 * values that never reach the entity's columns. Left in, they make the entity's own history
 * claim a column change that did not happen — a wrong entry, and the trail sitting beside the
 * translation entry contradicting it.
 *
 * The database is read back in each case, so the assertions are anchored to what the table
 * actually holds rather than to what the bridge believes.
 */
final class TranslationOwnColumnsTest extends GedmoBridgeTestCase
{
    public function testANonDefaultLocaleWriteLeavesNoOrdinaryEntryClaimingAColumnChange(): void
    {
        $page = new AuditedTranslatablePage('English title');
        $this->given($page);
        $identifier = $page->getId();

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $this->assertOneTranslationEntry(AuditedTranslatablePage::class, 'fr');

        self::assertSame(
            [],
            $this->ordinaryEntriesFor(AuditedTranslatablePage::class),
            'The only field written went to a translation row, so the entity own history has nothing truthful to say.',
        );

        $this->em()->clear();
        $reloaded = $this->em()->find(AuditedTranslatablePage::class, $identifier);

        self::assertInstanceOf(AuditedTranslatablePage::class, $reloaded);
        self::assertSame('English title', $reloaded->getTitle(), 'The entity own column must be untouched — which is exactly why claiming it changed would be a lie.');
    }

    public function testOnlyTheDivertedFieldsAreSubtractedFromTheEntitysOwnEntry(): void
    {
        $page = new AuditedTranslatablePage('English title');
        $this->given($page);
        $identifier = $page->getId();

        $page->translateInto('fr');
        $page->rename('Titre français');
        $page->renumber('REF-2');
        $this->save($page);

        $ordinary = $this->ordinaryEntriesFor(AuditedTranslatablePage::class);

        self::assertCount(1, $ordinary, 'A column Gedmo does not divert still deserves the entity own update entry.');
        $this->assertRecordedFieldsAre($ordinary[0], ['reference']);
        $this->assertFieldChanged($ordinary[0], 'reference', 'REF-1', 'REF-2');

        $this->assertFieldRecorded($this->assertOneTranslationEntry(AuditedTranslatablePage::class, 'fr'), 'title', [
            'locale' => 'fr',
            'before' => 'English title',
            'after' => 'Titre français',
        ]);

        $this->em()->clear();
        $reloaded = $this->em()->find(AuditedTranslatablePage::class, $identifier);

        self::assertInstanceOf(AuditedTranslatablePage::class, $reloaded);
        self::assertSame('REF-2', $reloaded->getReference());
        self::assertSame('English title', $reloaded->getTitle());
    }

    public function testTheDefaultLocaleKeepsTranslatableFieldsInTheEntitysOwnEntry(): void
    {
        $page = new AuditedTranslatablePage('English title');
        $this->given($page);
        $identifier = $page->getId();

        $page->rename('Another English title');
        $this->save($page);

        $this->assertNoTranslationEntryRecorded();

        $entry = $this->assertOneEntry(AuditAction::Update, AuditedTranslatablePage::class);
        $this->assertFieldChanged($entry, 'title', 'English title', 'Another English title');

        $this->em()->clear();
        $reloaded = $this->em()->find(AuditedTranslatablePage::class, $identifier);

        self::assertInstanceOf(AuditedTranslatablePage::class, $reloaded);
        self::assertSame('Another English title', $reloaded->getTitle(), 'A default-locale write does reach the column, so the entry is right to say so.');
    }
}
