<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * One fact happened; a cascade is its consequence, not more facts.
 *
 * Deleting an aggregate can schedule dozens of children in the same flush. Recording each of
 * them buries "the post was deleted" under thirty rows that say nothing a reader did not already
 * infer — and the root's own entry already carries its full state at deletion.
 *
 * Suppression is opt-in, not a default. The rule follows every to-one association rather than
 * proving the deletion was really a cascade, so two deliberate deletions in one flush can be
 * collapsed into one entry — a fact lost is worse than noise kept. An application that finds the
 * burst real turns it on, and then a cascaded child records nothing of its own.
 */
#[CoversNothing]
final class CascadeSuppressionTest extends IntegrationTestCase
{
    public function testByDefaultEveryCascadedChildIsRecordedOnItsOwn(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        new DeepChild($comment, 'A footnote');
        $this->given($post);

        $this->remove($post);

        $this->assertRecordedCount(3);
        $this->assertEntriesRecorded(AuditAction::Delete, Post::class, 1);
        $this->assertEntriesRecorded(AuditAction::Delete, Comment::class, 1);
        $this->assertEntriesRecorded(AuditAction::Delete, DeepChild::class, 1);
    }

    public function testSuppressionCanBeTurnedOnAndThenOnlyTheRootIsRecorded(): void
    {
        $this->rebootWith(['capture' => ['suppress_cascade_children' => true]]);

        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        new DeepChild($comment, 'A footnote');
        $this->given($post);

        $postId = $post->getId();
        $this->remove($post);

        $this->assertRecordedCount(1);
        $this->assertOneEntry(AuditAction::Delete, Post::class, entityId: $postId);
        $this->assertNoEntryFor(Comment::class);
        $this->assertNoEntryFor(DeepChild::class);

        self::assertSame(0, $this->countRowsIn('fixture_comments'));
        self::assertSame(0, $this->countRowsIn('fixture_deep_children'));
    }

    public function testDeletingAChildOnItsOwnIsStillRecorded(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $this->given($post);

        $commentId = $comment->getId();
        $this->remove($comment);

        $this->assertRecordedCount(1);
        $entry = $this->assertOneEntry(AuditAction::Delete, Comment::class, entityId: $commentId);
        $this->assertRootIs($entry, 'post', (string) $post->getId(), 'Analytical Engine');
        $this->assertNoEntryFor(Post::class);
    }

    public function testSuppressionOnlyAppliesToDeletionsHappeningInTheSameFlush(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $this->given($post);

        $comment->edit('An edited remark');
        $this->em()->flush();

        $this->assertRecordedCount(1);
        $this->assertOneEntry(AuditAction::Update, Comment::class);
    }
}
