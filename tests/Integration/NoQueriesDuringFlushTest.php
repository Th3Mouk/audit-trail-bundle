<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Author;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\StringableEntity;

/**
 * Capture must cost nothing on the wire.
 *
 * Every convenience the trail offers — a label snapshot, an aggregate root, an association
 * reference — is a temptation to read one more row in the middle of someone else's flush. A
 * single lazy load there turns a two-statement write into an N+1 nobody asked for, inside a
 * transaction that is already holding locks.
 *
 * So the contract is absolute: capture resolves what is already in memory and returns null for
 * the rest. A missing label makes an entry less readable; a query makes the application slower
 * in production, invisibly.
 *
 * Observed through DoctrineBundle's debug middleware, which records what the driver executed.
 * Each test first proves the observation works — the window must contain the write itself —
 * before claiming what the window does *not* contain.
 */
#[CoversNothing]
final class NoQueriesDuringFlushTest extends IntegrationTestCase
{
    public function testUpdatingAnEntityWhoseScopeIsUnloadedIssuesNoSelect(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $this->given($post, $comment);
        $this->em()->clear();

        $reloaded = $this->em()->find(Comment::class, $comment->getId());
        self::assertNotNull($reloaded);

        $statements = $this->sqlExecutedDuring(function () use ($reloaded): void {
            $reloaded->edit('An edited remark');
            $this->em()->flush();
        });

        $this->assertFlushWasObserved($statements);
        $this->assertNoSelectDuring($statements);

        $entry = $this->assertOneEntry(AuditAction::Update, Comment::class);
        $this->assertRootIs($entry, 'post', (string) $post->getId());
    }

    /**
     * The limit of the promise, stated on purpose: a hop that is not in memory ends the walk.
     *
     * An entry without a root is still a correct entry, and it is the only answer available at
     * zero cost. Applications that need the root on a deep child either load the intermediate
     * or implement the escape hatch, which decides the root without walking anything.
     */
    public function testAnUnloadedIntermediateHopYieldsNoRootRatherThanALoad(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $child = new DeepChild($comment, 'A footnote');
        $this->given($post, $comment, $child);
        $this->em()->clear();

        $reloaded = $this->em()->find(DeepChild::class, $child->getId());
        self::assertNotNull($reloaded);

        $statements = $this->sqlExecutedDuring(function () use ($reloaded): void {
            $reloaded->annotate('An edited footnote');
            $this->em()->flush();
        });

        $this->assertFlushWasObserved($statements);
        $this->assertNoSelectDuring($statements);

        $this->assertNoRoot($this->assertOneEntry(AuditAction::Update, DeepChild::class));
    }

    public function testCreatingAnEntityIssuesNoSelect(): void
    {
        $author = new Author('Ada Lovelace');
        $this->given($author);
        $this->em()->clear();

        $reloadedAuthor = $this->em()->getReference(Author::class, $author->getId());
        self::assertNotNull($reloadedAuthor);

        $statements = $this->sqlExecutedDuring(function () use ($reloadedAuthor): void {
            $this->em()->persist(new Post('Analytical Engine', $reloadedAuthor));
            $this->em()->flush();
        });

        $this->assertFlushWasObserved($statements);
        $this->assertNoSelectDuring($statements);

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);
        $this->assertFieldRecorded($entry, 'author', $this->aRef(Author::class, (string) $author->getId()));
    }

    public function testDeletingAnEntityIssuesNoSelect(): void
    {
        $entity = new StringableEntity('Analytical Engine');
        $this->given($entity);
        $this->em()->clear();

        $reloaded = $this->em()->find(StringableEntity::class, $entity->getId());
        self::assertNotNull($reloaded);

        $statements = $this->sqlExecutedDuring(function () use ($reloaded): void {
            $this->em()->remove($reloaded);
            $this->em()->flush();
        });

        $this->assertFlushWasObserved($statements);
        $this->assertNoSelectDuring($statements);

        $entry = $this->assertOneEntry(AuditAction::Delete, StringableEntity::class);
        $this->assertLabelIs($entry, 'Stringable Analytical Engine');
    }

    /**
     * The invariant stated the only way that cannot be argued with: capture adds no query.
     *
     * Deleting an aggregate makes the ORM load the collections it has to cascade into, and that
     * read is Doctrine's business, not the trail's. Rather than trying to name which statements
     * belong to whom, the same deletion is measured twice — once with capture on, once with the
     * bundle switched off — and the two counts have to match.
     */
    public function testCaptureAddsNoStatementToAFlushDoctrineAlreadyHadToMake(): void
    {
        $withCapture = $this->statementsOfCascadingDeletion();
        self::assertSame(1, $this->countAuditRows(), 'The measured flush must really have been captured.');

        $this->rebootWith(['enabled' => false]);

        $withoutCapture = $this->statementsOfCascadingDeletion();
        self::assertSame(0, $this->countAuditRows(), 'The comparison flush must really have been silent.');

        self::assertCount(
            \count(self::selectsAmong($withoutCapture)),
            self::selectsAmong($withCapture),
            \sprintf(
                "Capture added a read of its own. With capture:\n%s\n\nWithout:\n%s",
                self::describeSql($withCapture),
                self::describeSql($withoutCapture),
            ),
        );
    }

    public function testAnUnloadedAssociationIsCapturedWithoutALabelRatherThanLoaded(): void
    {
        $ada = new Author('Ada Lovelace');
        $grace = new Author('Grace Hopper');
        $post = new Post('Analytical Engine', $ada);
        $this->given($ada, $grace, $post);
        $this->em()->clear();

        $reloadedPost = $this->em()->find(Post::class, $post->getId());
        self::assertNotNull($reloadedPost);
        $reference = $this->em()->getReference(Author::class, $grace->getId());
        self::assertNotNull($reference);

        $statements = $this->sqlExecutedDuring(function () use ($reloadedPost, $reference): void {
            $reloadedPost->assignTo($reference);
            $this->em()->flush();
        });

        $this->assertFlushWasObserved($statements);
        $this->assertNoSelectDuring($statements);

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);
        $this->assertFieldChanged(
            $entry,
            'author',
            $this->aRef(Author::class, (string) $ada->getId()),
            $this->aRef(Author::class, (string) $grace->getId()),
        );
    }

    /**
     * @return list<string>
     */
    private function statementsOfCascadingDeletion(): array
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        new DeepChild($comment, 'A footnote');
        $this->given($post);
        $this->em()->clear();

        $reloaded = $this->em()->find(Post::class, $post->getId());
        self::assertNotNull($reloaded);

        return $this->sqlExecutedDuring(function () use ($reloaded): void {
            $this->em()->remove($reloaded);
            $this->em()->flush();
        });
    }

    /**
     * @param list<string> $statements
     */
    private function assertFlushWasObserved(array $statements): void
    {
        $wrote = array_filter(
            $statements,
            fn (string $statement): bool => str_contains(strtolower($statement), $this->auditTable()),
        );

        self::assertNotSame(
            [],
            $wrote,
            \sprintf(
                "The SQL window never saw the trail being written, so it proves nothing. Executed:\n%s",
                self::describeSql($statements),
            ),
        );
    }

    /**
     * @param list<string> $statements
     */
    private function assertNoSelectDuring(array $statements): void
    {
        self::assertSame(
            [],
            self::selectsAmong($statements),
            \sprintf("Capture read from the database during the flush. Executed:\n%s", self::describeSql($statements)),
        );
    }
}
