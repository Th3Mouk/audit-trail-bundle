<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * A creation names the row it created, even when the database is the one choosing its key.
 *
 * The key of such an entity does not exist while `onFlush` runs, so its entry cannot be built
 * there. It is built — in full, from the same state — the moment the INSERT has produced the key,
 * and written then, inside the transaction that is still open.
 *
 * A key the entity already carries before its INSERT is not that key. `clone` keeps the source's
 * identifier, and Doctrine ignores it on insert; reading it would file the clone's history under
 * the source's.
 */
#[CoversNothing]
final class DatabaseAssignedIdentifierTest extends IntegrationTestCase
{
    public function testEachCreationIsRecordedUnderItsOwnKey(): void
    {
        $first = new Post('First');
        $second = new Post('Second');

        $this->save($first, $second);

        self::assertNotSame($first->getId(), $second->getId());
        $this->assertRecordedCount(2);

        $firstEntry = $this->assertOneEntry(AuditAction::Create, Post::class, entityId: (string) $first->getId());
        $secondEntry = $this->assertOneEntry(AuditAction::Create, Post::class, entityId: (string) $second->getId());

        $this->assertLabelIs($firstEntry, 'First');
        $this->assertLabelIs($secondEntry, 'Second');
    }

    public function testTheInitialStateNamesTheKeyTheDatabaseChose(): void
    {
        $post = new Post('Analytical Engine');

        $this->save($post);

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);

        $this->assertFieldRecorded($entry, 'id', $post->getId());
        $this->assertFieldRecorded($entry, 'title', 'Analytical Engine');
    }

    public function testTheEntryStillCarriesWhoDidItAndWhen(): void
    {
        $this->actor()->will($this->anActor(id: '77', type: 'operator', label: 'Grace Hopper'));

        $this->save(new Post('Analytical Engine'));

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);
        $this->assertActorIs($entry, '77', 'operator', 'Grace Hopper');
        self::assertEqualsWithDelta(time(), $entry->occurredAt->getTimestamp(), 5);
    }

    public function testAnEntryRecordedWithoutStateIsStillIdentified(): void
    {
        $this->rebootWith(['capture' => ['state_on_create' => false]]);
        $post = new Post('Analytical Engine');

        $this->save($post);

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class, entityId: (string) $post->getId());
        self::assertNull($entry->changes);
    }

    public function testAChildCreatedWithItsParentIsRootedAtTheParentsOwnKey(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');

        $this->save($post, $comment);

        $this->assertRecordedCount(2);
        $entry = $this->assertOneEntry(AuditAction::Create, Comment::class, entityId: (string) $comment->getId());

        $this->assertRootIs($entry, 'post', (string) $post->getId(), 'Analytical Engine');
        $this->assertFieldRecorded($entry, 'post', $this->aRef(Post::class, (string) $post->getId(), 'Analytical Engine'));
    }

    public function testInterleavedParentsAndChildrenEachKeepTheirOwnKeyAndRoot(): void
    {
        $created = [];

        for ($index = 1; $index <= 6; ++$index) {
            $post = new Post(\sprintf('Post %d', $index));
            $created[] = $post;
            $created[] = new Comment($post, \sprintf('Remark %d', $index));
        }

        $this->save(...$created);

        $this->assertRecordedCount(12);

        foreach ($created as $entity) {
            if ($entity instanceof Post) {
                $this->assertOneEntry(AuditAction::Create, Post::class, entityId: (string) $entity->getId());

                continue;
            }

            $entry = $this->assertOneEntry(AuditAction::Create, Comment::class, entityId: (string) $entity->getId());
            $this->assertRootIs($entry, 'post', (string) $entity->getPost()->getId(), $entity->getPost()->getTitle());
        }
    }

    public function testACloneKeepingItsSourcesKeyIsRecordedUnderItsOwn(): void
    {
        $source = new Post('Original');
        $this->given($source);
        $sourceId = $source->getId();

        $clone = clone $source;
        self::assertSame($sourceId, $clone->getId(), 'The clone must carry the stale key this test is about.');

        $this->save($clone);

        self::assertNotSame($sourceId, $clone->getId(), 'The database did assign the clone a key of its own.');
        $this->assertRecordedCount(1);
        $entry = $this->assertOneEntry(AuditAction::Create, Post::class, entityId: (string) $clone->getId());
        $this->assertFieldRecorded($entry, 'id', $clone->getId());
    }

    public function testAClonedChildOfAClonedParentIsRootedAtTheCloneNotAtTheSource(): void
    {
        $source = new Post('Original');
        $sourceComment = new Comment($source, 'A remark');
        $this->given($source, $sourceComment);

        $cloneOfPost = clone $source;
        $this->giveNoComments($cloneOfPost);
        $cloneOfComment = clone $sourceComment;
        $this->pointCommentAt($cloneOfComment, $cloneOfPost);

        $this->save($cloneOfPost, $cloneOfComment);

        $this->assertRecordedCount(2);
        $entry = $this->assertOneEntry(AuditAction::Create, Comment::class, entityId: (string) $cloneOfComment->getId());

        $this->assertRootIs($entry, 'post', (string) $cloneOfPost->getId(), 'Original');
        $this->assertFieldRecorded($entry, 'post', $this->aRef(Post::class, (string) $cloneOfPost->getId(), 'Original'));
        self::assertNotSame($source->getId(), $cloneOfPost->getId());
    }

    /**
     * The limit of the promise, stated on purpose: an entry built before the INSERT cannot name
     * what has no key yet.
     *
     * An update is captured in `onFlush`, so a reference it holds to an entity still waiting for its
     * key is recorded as absent — and so is the root that entity would have been. What it must not be
     * is the key the new entity inherited from the row it was cloned from, which would read as a
     * move to the original.
     */
    public function testAnUpdateReferencingACloneStillAwaitingItsKeyNamesNeitherItNorItsSource(): void
    {
        $source = new Post('Original');
        $comment = new Comment($source, 'A remark');
        $this->given($source, $comment);

        $cloneOfPost = clone $source;
        $this->giveNoComments($cloneOfPost);
        $this->pointCommentAt($comment, $cloneOfPost);

        $this->save($cloneOfPost, $comment);

        $entry = $this->assertOneEntry(AuditAction::Update, Comment::class, entityId: (string) $comment->getId());

        $this->assertFieldChanged($entry, 'post', $this->aRef(Post::class, (string) $source->getId(), 'Original'), null);
        $this->assertNoRoot($entry);
    }

    public function testTheRowIsInsertedOnceAndNeverUpdatedToReceiveItsKey(): void
    {
        $statements = $this->sqlExecutedDuring(function (): void {
            $this->save(new Post('Analytical Engine'));
        });

        $onTheTrail = array_values(array_filter(
            $statements,
            fn (string $statement): bool => str_contains(strtolower($statement), $this->auditTable()),
        ));

        self::assertCount(1, $onTheTrail, \sprintf("Expected a single write to the trail. Executed:\n%s", self::describeSql($statements)));
        self::assertStringStartsWith('INSERT', strtoupper(ltrim($onTheTrail[0])));
        self::assertSame([], self::selectsAmong($statements), \sprintf("Capture read during the flush. Executed:\n%s", self::describeSql($statements)));
    }

    public function testAFlushThatFailsAfterTheEntryWasWrittenTakesItBack(): void
    {
        $writtenBeforeTheFailure = null;
        $this->em()->getEventManager()->addEventListener(
            Events::postPersist,
            new readonly class(function () use (&$writtenBeforeTheFailure): void {
                $writtenBeforeTheFailure = $this->countAuditRows();

                throw new \RuntimeException('The flush fails once its INSERTs are done.');
            }) {
                /**
                 * @param \Closure(): void $fail
                 */
                public function __construct(private \Closure $fail)
                {
                }

                public function postPersist(PostPersistEventArgs $args): void
                {
                    ($this->fail)();
                }
            },
        );

        try {
            $this->save(new Post('Doomed'));
            self::fail('The flush was expected to fail.');
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $writtenBeforeTheFailure, 'The entry should have been written before the failure, or the rollback proves nothing.');
        self::assertSame(0, $this->countRowsIn('fixture_posts'));
        self::assertSame(0, $this->countAuditRows());
    }

    public function testAnEntryWrittenAfterTheInsertIsIdenticalToOneTheUnitOfWorkWrites(): void
    {
        $this->actor()->will($this->anActor(id: '77', type: 'operator', label: 'Grace Hopper'));
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $post->rewrite('Notes on the Analytical Engine.');
        $post->publishOn($this->anInstant('1843-10-01 00:00:00'));

        $this->save($post, $comment);

        $written = $this->auditRowsWithoutIdentity();
        $entries = $this->spiedEntries();
        self::assertCount(2, $written);
        self::assertCount(2, $entries);
        $this->forgetRecordedEntries();

        $storage = $this->service(AuditStorageInterface::class);
        \assert($storage instanceof AuditStorageInterface);

        foreach ($entries as $entry) {
            $storage->store($entry);
        }
        $this->em()->flush();

        self::assertSame($this->sortedByEntity($written), $this->sortedByEntity($this->auditRowsWithoutIdentity()));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sortedByEntity(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a['entity_type'], $a['entity_id']] <=> [$b['entity_type'], $b['entity_id']]);

        return $rows;
    }

    private function giveNoComments(Post $post): void
    {
        (new \ReflectionProperty(Post::class, 'comments'))->setValue($post, new ArrayCollection());
    }

    private function pointCommentAt(Comment $comment, Post $post): void
    {
        (new \ReflectionProperty(Comment::class, 'post'))->setValue($comment, $post);
        (new \ReflectionProperty(Comment::class, 'children'))->setValue($comment, new ArrayCollection());
    }
}
