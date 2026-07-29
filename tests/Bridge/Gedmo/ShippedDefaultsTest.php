<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\AuditedTranslatablePage;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\RestorableSoftDeleteablePage;
use Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity\SoftDeleteablePage;

/**
 * What a fresh installation gets: no `audit_trail` configuration at all, Gedmo at priority 0.
 *
 * The rest of the suite moves capture above Gedmo's rewriters, which is what
 * `docs/bridges/gedmo.md` tells an application to do. This file removes even that, because the
 * headline feature has to work without it — the defect being guarded against is a translation
 * listener whose default priority puts it on the wrong side of Gedmo, which produces no error and
 * no entry, and which a suite that tunes the priority would never notice.
 *
 * The one case that still needs capture moved is asserted here too, as the gap it is.
 */
final class ShippedDefaultsTest extends GedmoBridgeTestCase
{
    public function testTranslationAuditingWorksWithNoPriorityTuningAtAll(): void
    {
        $this->bootWith(['listener_priority' => 0]);

        $page = new AuditedTranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $entry = $this->assertOneTranslationEntry(AuditedTranslatablePage::class, 'fr');
        $this->assertFieldRecorded($entry, 'title', [
            'locale' => 'fr',
            'before' => 'English title',
            'after' => 'Titre français',
        ]);
    }

    public function testTheEntitysOwnEntryIsStillTruthfulAtTheShippedDefault(): void
    {
        $this->bootWith(['listener_priority' => 0]);

        $page = new AuditedTranslatablePage('English title');
        $this->given($page);
        $identifier = $page->getId();

        $page->translateInto('fr');
        $page->rename('Titre français');
        $page->renumber('REF-2');
        $this->save($page);

        $ordinary = $this->ordinaryEntriesFor(AuditedTranslatablePage::class);

        self::assertCount(1, $ordinary);
        $this->assertRecordedFieldsAre($ordinary[0], ['reference']);

        $this->em()->clear();
        $reloaded = $this->em()->find(AuditedTranslatablePage::class, $identifier);

        self::assertInstanceOf(AuditedTranslatablePage::class, $reloaded);
        self::assertSame('English title', $reloaded->getTitle());
        self::assertSame('REF-2', $reloaded->getReference());
    }

    public function testAHandMadeSoftDeleteNeedsNoPriorityTuningEither(): void
    {
        $this->bootWith(['listener_priority' => 0]);

        $page = new RestorableSoftDeleteablePage('Deleted without remove()');
        $this->given($page);

        $page->softDelete($this->anInstant('2026-02-01 09:00:00'));
        $this->save($page);

        $entry = $this->assertOneEntry(AuditAction::Delete, RestorableSoftDeleteablePage::class);
        $this->assertFieldRecorded($entry, 'title', 'Deleted without remove()');
    }

    /**
     * The documented gap, asserted as a gap.
     *
     * `$em->remove()` on a soft-deleteable entity is the one case a bridge default cannot rescue:
     * Gedmo rewrites the removal before capture's turn, leaving the entity in neither
     * `entityDeletions` nor `entityUpdates`, so there is no change set left to reclassify. Raising
     * `audit_trail.listener_priority` above Gedmo is the only fix, and
     * `SoftDeleteAuditTest` asserts it works once you do.
     */
    public function testRemoveOnASoftDeleteableEntityStillNeedsCaptureAboveGedmo(): void
    {
        $this->bootWith(['listener_priority' => 0]);

        $page = new SoftDeleteablePage('Removed at the shipped default');
        $this->given($page);
        $identifier = $page->getId();

        $this->remove($page);

        $this->assertNoEntryFor(SoftDeleteablePage::class);

        $this->em()->clear();
        $this->em()->getFilters()->disable('soft_deleteable');
        $survivor = $this->em()->find(SoftDeleteablePage::class, $identifier);

        self::assertInstanceOf(SoftDeleteablePage::class, $survivor, 'The row survived and nothing in the trail says so: that is the gap, and it is what listener_priority exists for.');
    }
}
