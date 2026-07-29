<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * Whether a row exists at all, and what it is allowed to say.
 *
 * Two rules meet on the same property list and are easy to conflate. Ignoring a field means
 * the change is not even an event: a flush that touches nothing else writes no row. Masking a
 * field means the change *is* an event whose value must never be stored: a flush that touches
 * nothing else writes exactly one row, carrying a sentinel.
 *
 * The masked value is asserted absent from the entire table, not merely from the field it
 * belongs to — a secret that leaks into a label or into metadata has still leaked.
 */
#[CoversNothing]
final class RowTriggerRuleTest extends IntegrationTestCase
{
    public function testChangingOnlyAnIgnoredPropertyWritesNoRow(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->annotate('an internal remark');
        $this->em()->flush();

        $this->assertNothingRecorded();
        self::assertSame(0, $this->countAuditRows());

        $this->em()->clear();
        $reloaded = $this->em()->find(Post::class, $post->getId());
        self::assertNotNull($reloaded);
        self::assertSame('an internal remark', $reloaded->getInternalNotes(), 'The change itself must still be persisted.');
    }

    public function testChangingOnlyAMaskedPropertyWritesExactlyOneRowWithTheSentinel(): void
    {
        $post = new Post('Analytical Engine');
        $post->rotateSecret('old-secret');
        $this->given($post);

        $post->rotateSecret('new-secret');
        $this->em()->flush();

        $this->assertRecordedCount(1);
        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertRecordedFieldsAre($entry, ['secret']);
        $this->assertFieldMasked($entry, 'secret');

        $dump = $this->auditTableDump();
        self::assertStringNotContainsString('old-secret', $dump);
        self::assertStringNotContainsString('new-secret', $dump);
    }

    public function testAPerPropertyMaskOverridesTheGlobalOne(): void
    {
        $post = new Post('Analytical Engine');
        $post->rotateApiKey('ak_live_0001');
        $this->given($post);

        $post->rotateApiKey('ak_live_0002');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertFieldMasked($entry, 'apiKey', '[redacted]');

        $dump = $this->auditTableDump();
        self::assertStringNotContainsString('ak_live_0001', $dump);
        self::assertStringNotContainsString('ak_live_0002', $dump);
    }

    public function testTheGlobalMaskIsConfigurable(): void
    {
        $this->rebootWith(['mask' => '<<hidden>>']);

        $post = new Post('Analytical Engine');
        $post->rotateSecret('old-secret');
        $this->given($post);

        $post->rotateSecret('new-secret');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertFieldMasked($entry, 'secret', '<<hidden>>');
    }

    public function testAnIgnoredChangeRidingAlongsideAMaskedOneNeverAppears(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->annotate('an internal remark');
        $post->rotateSecret('new-secret');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertRecordedFieldsAre($entry, ['secret']);
        self::assertStringNotContainsString('an internal remark', $this->auditTableDump());
    }

    public function testAnIgnoredChangeRidingAlongsideATrackedOneNeverAppears(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->annotate('an internal remark');
        $post->rename('The Analytical Engine');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertRecordedFieldsAre($entry, ['title']);
    }
}
