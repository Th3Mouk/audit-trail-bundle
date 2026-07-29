<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\AuditedTranslatablePage;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\RestorableSoftDeleteablePage;

/**
 * `translatable: false` and `soft_deleteable: false`, asserted where it counts: on the trail.
 *
 * A container assertion says a service is gone. These say what an application actually loses by
 * switching a half of the bridge off — including the part that is not free. Both halves are
 * independent, and neither of them switches the other off.
 */
final class GedmoBridgeOptionsTest extends GedmoBridgeTestCase
{
    public function testTranslatableFalseStopsRecordingTranslatedContent(): void
    {
        $this->bootWith(['bridges' => ['gedmo' => ['translatable' => false]]]);

        $page = new AuditedTranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $this->assertNoTranslationEntryRecorded();
    }

    /**
     * The price of switching the translation half off, stated rather than implied.
     *
     * The same option owns the field exclusion, so with it gone capture keeps the translatable
     * field it read before Gedmo reverted it, and the entity's own entry claims a column change
     * the table never sees. There is no third setting: either the bridge follows translations or
     * it stops trimming them.
     */
    public function testTranslatableFalseAlsoStopsTrimmingDivertedFieldsFromTheEntitysOwnEntry(): void
    {
        $this->bootWith(['bridges' => ['gedmo' => ['translatable' => false]]]);

        $page = new AuditedTranslatablePage('English title');
        $this->given($page);
        $identifier = $page->getId();

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $entry = $this->assertOneEntry(AuditAction::Update, AuditedTranslatablePage::class);
        $this->assertFieldChanged($entry, 'title', 'English title', 'Titre français');

        $this->em()->clear();
        $reloaded = $this->em()->find(AuditedTranslatablePage::class, $identifier);

        self::assertInstanceOf(AuditedTranslatablePage::class, $reloaded);
        self::assertSame(
            'English title',
            $reloaded->getTitle(),
            'The entry above says the column changed and the column did not: that is what this option costs.',
        );
    }

    public function testSoftDeleteableFalseLeavesALogicalDeleteAnOrdinaryUpdate(): void
    {
        $this->bootWith(['bridges' => ['gedmo' => ['soft_deleteable' => false]]]);

        $page = new RestorableSoftDeleteablePage('Not reclassified');
        $this->given($page);

        $page->softDelete($this->anInstant('2026-02-01 09:00:00'));
        $this->save($page);

        $entry = $this->assertOneEntry(AuditAction::Update, RestorableSoftDeleteablePage::class);
        $this->assertFieldChanged($entry, 'deletedAt', null, '2026-02-01T09:00:00+00:00');
        self::assertSame([], $this->entriesMatching(AuditAction::Delete, RestorableSoftDeleteablePage::class));
    }

    public function testSoftDeleteableFalseLeavesTranslationAuditingAlone(): void
    {
        $this->bootWith(['bridges' => ['gedmo' => ['soft_deleteable' => false]]]);

        $page = new AuditedTranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->rename('Titre français');
        $this->save($page);

        $this->assertOneTranslationEntry(AuditedTranslatablePage::class, 'fr');
    }

    public function testTranslatableFalseLeavesTheSoftDeleteMappingAlone(): void
    {
        $this->bootWith(['bridges' => ['gedmo' => ['translatable' => false]]]);

        $page = new RestorableSoftDeleteablePage('Still reclassified');
        $this->given($page);

        $page->softDelete($this->anInstant('2026-02-01 09:00:00'));
        $this->save($page);

        $this->assertOneEntry(AuditAction::Delete, RestorableSoftDeleteablePage::class);
    }
}
