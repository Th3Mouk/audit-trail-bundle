<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\RestorableSoftDeleteablePage;
use Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity\SoftDeleteablePage;

/**
 * A logical delete reads as a deletion, and coming back reads as something else.
 *
 * Gedmo cancels the removal and stamps a date instead, so the row survives while the trail must
 * still say "deleted". A restore moves the same column the other way and must not be mistaken
 * for one.
 *
 * Two ways in, and both have to arrive at the same place: `$em->remove()`, which Gedmo rewrites,
 * and a bare `setDeletedAt()`, which Gedmo never sees. The second is the common one in real
 * applications and no listener ordering reaches it — only `SoftDeleteableActionResolver`, wired
 * into capture through `ActionResolverInterface`, does.
 */
final class SoftDeleteAuditTest extends GedmoBridgeTestCase
{
    public function testAHandMadeLogicalDeleteIsRecordedAsADeletionCarryingTheStateAtDeletion(): void
    {
        $page = new RestorableSoftDeleteablePage('Deleted without remove()');
        $this->given($page);
        $identifier = $page->getId();

        $page->softDelete($this->anInstant('2026-02-01 09:00:00'));
        $this->save($page);

        $entry = $this->assertOneEntry(AuditAction::Delete, RestorableSoftDeleteablePage::class);
        $this->assertFieldRecorded($entry, 'title', 'Deleted without remove()');
        $this->assertFieldRecorded($entry, 'deletedAt', '2026-02-01T09:00:00+00:00');
        $this->assertLabelIs($entry, 'Deleted without remove()');

        self::assertSame(
            [],
            $this->entriesMatching(AuditAction::Update, RestorableSoftDeleteablePage::class),
            'A logical delete must not also read as an update of a date column.',
        );

        $this->em()->clear();
        $this->em()->getFilters()->disable('soft_deleteable');
        $survivor = $this->em()->find(RestorableSoftDeleteablePage::class, $identifier);

        self::assertInstanceOf(RestorableSoftDeleteablePage::class, $survivor, 'Nothing was removed: the trail says "deleted" about a row that is still there, which is the whole point of a logical delete.');
        self::assertNotNull($survivor->getDeletedAt());
    }

    public function testMovingTheDeletionDateElsewhereStaysAnOrdinaryUpdate(): void
    {
        $page = new RestorableSoftDeleteablePage('Rescheduled');
        $page->softDelete($this->anInstant('2026-02-01 09:00:00'));
        $this->given($page);

        $page->softDelete($this->anInstant('2026-03-01 09:00:00'));
        $this->save($page);

        $entry = $this->assertOneEntry(AuditAction::Update, RestorableSoftDeleteablePage::class);
        $this->assertFieldChanged($entry, 'deletedAt', '2026-02-01T09:00:00+00:00', '2026-03-01T09:00:00+00:00');
        self::assertSame([], $this->entriesMatching(AuditAction::Delete, RestorableSoftDeleteablePage::class));
    }

    public function testRemovingASoftDeleteableEntityIsRecordedAsADeletion(): void
    {
        $page = new SoftDeleteablePage('Kept forever');
        $this->given($page);
        $identifier = $page->getId();

        $this->remove($page);

        $entry = $this->assertOneEntry(AuditAction::Delete, SoftDeleteablePage::class);
        $this->assertFieldRecorded($entry, 'title', 'Kept forever');
        $this->assertLabelIs($entry, 'Kept forever');
        self::assertSame([], $this->entriesMatching(AuditAction::Update, SoftDeleteablePage::class));

        $this->em()->clear();
        $this->em()->getFilters()->disable('soft_deleteable');
        $survivor = $this->em()->find(SoftDeleteablePage::class, $identifier);

        self::assertInstanceOf(SoftDeleteablePage::class, $survivor, 'Gedmo should have turned the removal into a stamped row rather than a real delete.');
        self::assertNotNull($survivor->getDeletedAt());
    }

    /**
     * Both halves of the round trip in one trail, because "distinctly" is a claim about the two
     * entries side by side. The deletion is deliberately not forgotten between the flushes.
     */
    public function testRestoringASoftDeletedEntityIsRecordedDistinctlyFromItsDeletion(): void
    {
        $page = new RestorableSoftDeleteablePage('Back from the dead');
        $this->given($page);

        $page->softDelete($this->anInstant('2026-02-01 09:00:00'));
        $this->save($page);

        $page->restore();
        $this->save($page);

        $deleted = $this->assertOneEntry(AuditAction::Delete, RestorableSoftDeleteablePage::class);
        $this->assertFieldRecorded($deleted, 'deletedAt', '2026-02-01T09:00:00+00:00');

        $restored = $this->assertOneEntry(AuditAction::Update, RestorableSoftDeleteablePage::class);
        $this->assertFieldChanged($restored, 'deletedAt', '2026-02-01T09:00:00+00:00', null);
    }
}
