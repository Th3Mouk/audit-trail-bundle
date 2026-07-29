<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo;

use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case\GedmoBridgeTestCase;
use Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture\AuditedTranslatablePage;

/**
 * A field policy is a property of the field, not of the write path.
 *
 * `#[AuditMasked]` still triggers an entry and still hides the value when the value travels to
 * a translation row instead of the entity's own column, and `#[NotAuditable]` still keeps the
 * whole thing out of the trail.
 */
final class TranslationFieldPolicyTest extends GedmoBridgeTestCase
{
    public function testAMaskedTranslatedFieldIsRecordedWithoutEitherOfItsValues(): void
    {
        $page = new AuditedTranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->changeSecret('secret français');
        $this->save($page);

        $entry = $this->assertOneTranslationEntry(AuditedTranslatablePage::class, 'fr');

        $this->assertRecordedFieldsAre($entry, ['secret']);
        $this->assertFieldRecorded($entry, 'secret', [
            'locale' => 'fr',
            'before' => '********',
            'after' => '********',
        ]);
    }

    public function testAnIgnoredTranslatedFieldIsNotRecordedAtAll(): void
    {
        $page = new AuditedTranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->annotate('note française');
        $this->save($page);

        $this->assertNoTranslationEntryRecorded();
        $this->assertNoEntryFor(AuditedTranslatablePage::class);
    }

    public function testAnIgnoredTranslatedFieldDoesNotSuppressTheFieldsBesideIt(): void
    {
        $page = new AuditedTranslatablePage('English title');
        $this->given($page);

        $page->translateInto('fr');
        $page->annotate('note française');
        $page->rename('Titre français');
        $this->save($page);

        $entry = $this->assertOneTranslationEntry(AuditedTranslatablePage::class, 'fr');

        $this->assertRecordedFieldsAre($entry, ['title']);
        $this->assertFieldRecorded($entry, 'title', [
            'locale' => 'fr',
            'before' => 'English title',
            'after' => 'Titre français',
        ]);
    }
}
