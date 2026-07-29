<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Author;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\PlainEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Enum\PostStatus;

/**
 * A creation is a single row that stands on its own.
 *
 * The initial state is stored in full because there is no earlier entry to reconstruct it
 * from: the first line of an entity's history has to be readable without the entity.
 */
#[CoversNothing]
final class CreateCaptureTest extends IntegrationTestCase
{
    public function testCreatingAnAuditableEntityWritesExactlyOneRowCarryingTheInitialState(): void
    {
        $author = new Author('Ada Lovelace');
        $post = new Post('Analytical Engine', $author);
        $post->rewrite('Notes on the Analytical Engine.');
        $post->retag('history', 'computing');
        $post->moveTo(PostStatus::Published);
        $post->publishOn($this->anInstant('1843-10-01 00:00:00'));
        $post->rotateSecret('s3cr3t');
        $post->annotate('reviewer only');

        $this->save($author, $post);

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);

        $this->assertLabelIs($entry, 'Analytical Engine');
        $this->assertFieldRecorded($entry, 'title', 'Analytical Engine');
        $this->assertFieldRecorded($entry, 'body', 'Notes on the Analytical Engine.');
        $this->assertFieldRecorded($entry, 'views', 0);
        $this->assertFieldRecorded($entry, 'status', 'published');
        $this->assertFieldRecorded($entry, 'publishedAt', '1843-10-01T00:00:00+00:00');
        $this->assertFieldRecorded($entry, 'author', $this->aRef(Author::class, (string) $author->getId(), 'Ada Lovelace'));
    }

    public function testAnEntityKeyedByTheApplicationIsIdentifiedOnItsCreateEntry(): void
    {
        $author = new Author('Ada Lovelace');

        $this->save($author);

        $entry = $this->assertOneEntry(AuditAction::Create, Author::class, entityId: (string) $author->getId());

        $this->assertLabelIs($entry, 'Ada Lovelace');
        $this->assertFieldRecorded($entry, 'name', 'Ada Lovelace');
    }

    /**
     * The known cost of capturing inside the flush, pinned so it cannot ship unnoticed.
     *
     * `onFlush` runs before the INSERT, so an entity whose key the database assigns has no
     * identifier yet — and the audit row is computed in that same moment in order to share the
     * transaction. The two guarantees are in tension: the entry is atomic, and it cannot name
     * the row it describes. Updates and deletions are unaffected, and an entity keyed by the
     * application is unaffected, so the trail is still navigable; only the very first entry of
     * a database-keyed entity has to be found by class and label instead of by identifier.
     */
    public function testACreateEntryCannotYetNameAnEntityWhoseKeyTheDatabaseAssigns(): void
    {
        $post = new Post('Analytical Engine');

        $this->save($post);

        self::assertNotNull($post->getId(), 'The database did assign a key.');

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);

        self::assertSame('', $entry->entityId);
        $this->assertLabelIs($entry, 'Analytical Engine');
    }

    /**
     * Values outside the documented mapping are dropped, not guessed at.
     *
     * The mapping covers scalars, dates, enums, entity references and stringables. A JSON array
     * column is none of those, so it is excluded — a wrong "after" in an audit trail is worse
     * than a missing one, and the exclusion is reported once at warning level for whoever wants
     * to register a serializer for it.
     */
    public function testAValueNoSerializerHandlesIsExcludedRatherThanGuessedAt(): void
    {
        $post = new Post('Analytical Engine');
        $post->retag('history', 'computing');

        $this->save($post);

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);

        $this->assertFieldRecorded($entry, 'title', 'Analytical Engine');
        $this->assertFieldNotRecorded($entry, 'tags');
    }

    public function testTheInitialStateHidesMaskedValuesAndOmitsIgnoredOnes(): void
    {
        $post = new Post('Analytical Engine');
        $post->rotateSecret('s3cr3t');
        $post->rotateApiKey('ak_live_0001');
        $post->annotate('reviewer only');

        $this->save($post);

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);

        $this->assertFieldRecorded($entry, 'secret', '********');
        $this->assertFieldRecorded($entry, 'apiKey', '[redacted]');
        $this->assertFieldNotRecorded($entry, 'internalNotes');

        $dump = $this->auditTableDump();
        self::assertStringNotContainsString('s3cr3t', $dump);
        self::assertStringNotContainsString('ak_live_0001', $dump);
        self::assertStringNotContainsString('reviewer only', $dump);
    }

    public function testCreatingANonAuditableEntityWritesNothing(): void
    {
        $this->save(new PlainEntity('Ordinary'));

        $this->assertNothingRecorded();
        self::assertSame(1, $this->countRowsIn('fixture_plain_entities'));
    }

    public function testOneRowPerCreatedEntityAndNoMore(): void
    {
        $author = new Author('Ada Lovelace');

        $this->save($author, new Post('First', $author), new Post('Second', $author));

        $this->assertRecordedCount(3);
        $this->assertEntriesRecorded(AuditAction::Create, Post::class, 2);
        $this->assertEntriesRecorded(AuditAction::Create, Author::class, 1);
        self::assertSame(3, $this->countAuditRows());
    }

    public function testTheEntryIsAttributedToWhoeverTheResolverChainNames(): void
    {
        $this->actor()->will($this->anActor(id: '77', type: 'operator', label: 'Grace Hopper'));

        $this->save(new Post('Analytical Engine'));

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);
        $this->assertActorIs($entry, '77', 'operator', 'Grace Hopper');
    }
}
