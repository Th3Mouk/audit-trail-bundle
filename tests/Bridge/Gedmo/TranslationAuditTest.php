<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity\TranslatablePage;
use Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity\TranslatablePageTranslation;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\GedmoKernel;

/**
 * Translated content, in the trail of the entity that was translated.
 *
 * Gedmo writes its translation rows two different ways from the same change set, and only one
 * of them goes through the UnitOfWork. Both are exercised here, and the one the bridge cannot
 * follow is asserted as the gap it is.
 */
final class TranslationAuditTest extends GedmoBridgeTestCase
{
    #[\Override]
    protected static function kernelUnderTest(): string
    {
        return GedmoKernel::class;
    }

    public function testAFieldTranslatedInANonDefaultLocaleIsRecordedAgainstItsSourceEntity(): void
    {
        $page = new TranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $entry = $this->assertOneTranslationEntry(TranslatablePage::class, 'fr');

        self::assertSame((string) $page->getId(), $entry->entityId);
        self::assertSame(AuditAction::Update, $entry->action);
        $this->assertRecordedFieldsAre($entry, ['title']);
        $this->assertFieldRecorded($entry, 'title', [
            'locale' => 'fr',
            'before' => 'English title',
            'after' => 'Titre français',
        ]);
        $this->assertLabelIs($entry, 'Titre français');
        self::assertSame(TranslatablePageTranslation::class, $entry->metadata['translation_class'] ?? null);
    }

    public function testGedmoWritesTheTranslationRowThroughTheUnitOfWork(): void
    {
        $page = new TranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        self::assertGreaterThan(
            0,
            $this->countRows(TranslatablePageTranslation::class),
            'Gedmo should have written a translation row: without one, the recorded entry would describe a translation that does not exist.',
        );
    }

    public function testATranslationTheApplicationPersistsItselfIsRecordedAgainstItsSourceEntity(): void
    {
        $page = new TranslatablePage('English title');
        $this->given($page);

        $page->addTranslation(new TranslatablePageTranslation('fr', 'title', 'Titre français'));
        $this->save($page);

        $entry = $this->assertOneTranslationEntry(TranslatablePage::class, 'fr');

        self::assertSame((string) $page->getId(), $entry->entityId);
        $this->assertFieldRecorded($entry, 'title', [
            'locale' => 'fr',
            'before' => null,
            'after' => 'Titre français',
        ]);
        self::assertSame(TranslatablePageTranslation::class, $entry->metadata['translation_class'] ?? null);
    }

    public function testAWriteInTheDefaultLocaleIsRecordedOnlyAsAnOrdinaryFieldChange(): void
    {
        $page = new TranslatablePage('English title');
        $this->given($page);

        $page->rename('Another English title');
        $this->save($page);

        $this->assertNoTranslationEntryRecorded();

        $entry = $this->assertOneEntry(AuditAction::Update, TranslatablePage::class);
        $this->assertFieldChanged($entry, 'title', 'English title', 'Another English title');
    }

    /**
     * The documented gap, asserted as a gap.
     *
     * A translation of an entity that has no identifier yet is inserted by Gedmo straight
     * through the DBAL connection, outside the UnitOfWork, and an onFlush-based audit cannot
     * name the row it would be talking about. See `src/Bridge/Gedmo/README.md`: assigning
     * identifiers before the flush (UUID) removes the case entirely.
     */
    public function testDirectDbalTranslationInsertIsNotYetCaptured(): void
    {
        $page = new TranslatablePage('English title');
        $page->translateInto('fr');
        $this->save($page);

        self::assertGreaterThan(
            0,
            $this->countRows(TranslatablePageTranslation::class),
            'Gedmo should have inserted the translation of a brand-new parent directly through the DBAL connection.',
        );
        $this->assertNoTranslationEntryRecorded();
        $this->assertOneEntry(AuditAction::Create, TranslatablePage::class);
    }

    public function testTheUncapturedTranslationInsertIsReportedOncePerClass(): void
    {
        $first = new TranslatablePage('First');
        $first->translateInto('fr');
        $this->save($first);

        $second = new TranslatablePage('Second');
        $second->translateInto('fr');
        $this->save($second);

        $logger = $this->recordedLogs();
        self::assertNotNull($logger, 'The bridge listener needs an optional PSR logger to report the uncovered path.');

        $reports = array_values(array_filter(
            $logger->records('warning'),
            static fn (array $record): bool => TranslatablePage::class === ($record['context']['class'] ?? null),
        ));

        self::assertCount(1, $reports, 'The uncovered translation path must be reported once per class, not once per flush.');
    }

    public function testTranslationOnlyFieldsAreTheFieldsGedmoDivertsAwayFromTheEntitysOwnColumns(): void
    {
        $page = new TranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');

        $diverted = $this->translationAuditListener()->translationOnlyFields($this->em(), $page);
        sort($diverted);

        self::assertSame(['body', 'title'], $diverted);
    }

    public function testNoFieldIsDivertedInTheDefaultLocale(): void
    {
        $page = new TranslatablePage('English title');
        $this->given($page);

        self::assertSame([], $this->translationAuditListener()->translationOnlyFields($this->em(), $page));
    }
}
