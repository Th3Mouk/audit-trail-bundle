<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Author;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Enum\PostStatus;

/**
 * The headline promise: a deletion stays readable after everything it named is gone.
 *
 * This is why capture happens in `onFlush` and not after it. At that moment the entity is
 * still fully hydrated and its associations are still resolvable, so the entry can carry the
 * whole state — including the *names* of the rows it pointed at. A foreign key cannot make
 * that promise; a snapshot can.
 */
#[CoversNothing]
final class DeleteCaptureTest extends IntegrationTestCase
{
    public function testDeletingWritesOneRowCarryingTheStateAsItStoodAtDeletion(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->rename('The Analytical Engine');
        $post->rewrite('Final text.');
        $post->recordView();
        $post->moveTo(PostStatus::Archived);
        $this->em()->flush();
        $this->forgetRecordedEntries();

        $postId = $post->getId();
        $this->remove($post);

        $entry = $this->assertOneEntry(AuditAction::Delete, Post::class, entityId: $postId);

        $this->assertLabelIs($entry, 'The Analytical Engine');
        $this->assertFieldRecorded($entry, 'title', 'The Analytical Engine');
        $this->assertFieldRecorded($entry, 'body', 'Final text.');
        $this->assertFieldRecorded($entry, 'views', 1);
        $this->assertFieldRecorded($entry, 'status', 'archived');
        self::assertSame(0, $this->countRowsIn('fixture_posts'));
    }

    public function testTheStateAtDeletionCarriesAssociationReferencesWithTheirLabels(): void
    {
        $author = new Author('Ada Lovelace');
        $post = new Post('Analytical Engine', $author);
        $this->given($author, $post);

        $this->remove($post);

        $entry = $this->assertOneEntry(AuditAction::Delete, Post::class);

        $this->assertFieldRecorded(
            $entry,
            'author',
            $this->aRef(Author::class, (string) $author->getId(), 'Ada Lovelace'),
        );
    }

    public function testTheEntryStaysFullyLegibleOnceTheRowsItNamesAreGone(): void
    {
        $author = new Author('Ada Lovelace');
        $authorId = (string) $author->getId();
        $post = new Post('Analytical Engine', $author);
        $this->given($author, $post);

        $postId = $post->getId();
        $this->remove($post);
        $this->remove($author);
        $this->em()->clear();

        self::assertSame(0, $this->countRowsIn('fixture_posts'));
        self::assertSame(0, $this->countRowsIn('fixture_authors'));

        $entry = $this->assertOneEntry(AuditAction::Delete, Post::class, entityId: $postId);

        $this->assertLabelIs($entry, 'Analytical Engine');
        $this->assertFieldRecorded($entry, 'author', $this->aRef(Author::class, $authorId, 'Ada Lovelace'));

        self::assertStringContainsString('Ada Lovelace', $this->auditTableDump());
    }

    public function testDeletingHidesMaskedValuesAndOmitsIgnoredOnes(): void
    {
        $post = new Post('Analytical Engine');
        $post->rotateSecret('s3cr3t');
        $post->rotateApiKey('ak_live_0001');
        $post->annotate('reviewer only');
        $this->given($post);

        $this->remove($post);

        $entry = $this->assertOneEntry(AuditAction::Delete, Post::class);

        $this->assertFieldRecorded($entry, 'secret', '********');
        $this->assertFieldRecorded($entry, 'apiKey', '[redacted]');
        $this->assertFieldNotRecorded($entry, 'internalNotes');

        $dump = $this->auditTableDump();
        self::assertStringNotContainsString('s3cr3t', $dump);
        self::assertStringNotContainsString('ak_live_0001', $dump);
    }

    public function testTheStateAtDeletionCanBeTurnedOff(): void
    {
        $this->rebootWith(['capture' => ['state_on_delete' => false]]);

        $post = new Post('Analytical Engine');
        $this->given($post);

        $this->remove($post);

        $entry = $this->assertOneEntry(AuditAction::Delete, Post::class);

        self::assertNull($entry->changes);
        $this->assertLabelIs($entry, 'Analytical Engine');
    }
}
