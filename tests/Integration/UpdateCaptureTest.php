<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Author;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Enum\PostStatus;

/**
 * An update is a diff, and only of what was actually tracked and actually changed.
 *
 * The change set comes from the UnitOfWork rather than from comparing the entity to a copy,
 * which is why this belongs here: only a real flush knows what Doctrine considers changed.
 */
#[CoversNothing]
final class UpdateCaptureTest extends IntegrationTestCase
{
    public function testUpdatingWritesOneRowWithTheBeforeAndAfterOfEveryChangedTrackedField(): void
    {
        $post = new Post('Analytical Engine');
        $post->rewrite('First draft.');
        $this->given($post);

        $post->rename('The Analytical Engine');
        $post->rewrite('Second draft.');
        $post->recordView();
        $post->moveTo(PostStatus::Published);
        $post->publishOn($this->anInstant('1843-10-01 00:00:00'));
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class, entityId: $post->getId());

        $this->assertRecordedFieldsAre($entry, ['title', 'body', 'views', 'status', 'publishedAt']);
        $this->assertFieldChanged($entry, 'title', 'Analytical Engine', 'The Analytical Engine');
        $this->assertFieldChanged($entry, 'body', 'First draft.', 'Second draft.');
        $this->assertFieldChanged($entry, 'views', 0, 1);
        $this->assertFieldChanged($entry, 'status', 'draft', 'published');
        $this->assertFieldChanged($entry, 'publishedAt', null, '1843-10-01T00:00:00+00:00');
    }

    public function testAChangeNoSerializerHandlesIsDroppedWhileTheRestOfTheDiffSurvives(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->retag('history');
        $post->rename('The Analytical Engine');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertRecordedFieldsAre($entry, ['title']);
    }

    public function testUnchangedFieldsAreAbsentFromTheDiff(): void
    {
        $post = new Post('Analytical Engine');
        $post->rewrite('Only the title moves.');
        $this->given($post);

        $post->rename('The Analytical Engine');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertRecordedFieldsAre($entry, ['title']);
    }

    public function testReassigningAnAssociationIsCapturedAsBothReferencesWithTheirLabels(): void
    {
        $ada = new Author('Ada Lovelace');
        $grace = new Author('Grace Hopper');
        $post = new Post('Analytical Engine', $ada);
        $this->given($ada, $grace, $post);

        $post->assignTo($grace);
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertFieldChanged(
            $entry,
            'author',
            $this->aRef(Author::class, (string) $ada->getId(), 'Ada Lovelace'),
            $this->aRef(Author::class, (string) $grace->getId(), 'Grace Hopper'),
        );
    }

    public function testDetachingAnAssociationRecordsTheReferenceItLost(): void
    {
        $ada = new Author('Ada Lovelace');
        $post = new Post('Analytical Engine', $ada);
        $this->given($ada, $post);

        $post->assignTo(null);
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertFieldChanged(
            $entry,
            'author',
            $this->aRef(Author::class, (string) $ada->getId(), 'Ada Lovelace'),
            null,
        );
    }

    public function testTwoUpdatesInTwoFlushesAreTwoRows(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->rename('Second title');
        $this->em()->flush();

        $post->rename('Third title');
        $this->em()->flush();

        $entries = $this->assertEntriesRecorded(AuditAction::Update, Post::class, 2);

        $this->assertFieldChanged($entries[0], 'title', 'Analytical Engine', 'Second title');
        $this->assertFieldChanged($entries[1], 'title', 'Second title', 'Third title');
    }

    public function testAFlushThatChangesNothingWritesNothing(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->rename('Analytical Engine');
        $this->em()->flush();

        $this->assertNothingRecorded();
    }
}
