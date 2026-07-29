<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * "Show me everything that happened to this thing" — one query, newest first.
 *
 * The whole reason the root is denormalised onto every entry is so an aggregate's history can
 * be rendered inline next to the aggregate itself. That is only worth doing if it costs one
 * indexed read, so the query count is part of the contract and is asserted here rather than
 * assumed.
 */
#[CoversNothing]
final class AggregateHistoryTest extends IntegrationTestCase
{
    public function testTheWholeAggregateHistoryComesBackInOneQueryNewestFirst(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $child = new DeepChild($comment, 'A footnote');
        $this->given($post, $comment, $child);

        $comment->edit('An edited remark');
        $this->em()->flush();

        $child->annotate('An edited footnote');
        $this->em()->flush();

        $this->em()->clear();

        $entries = [];
        $statements = $this->sqlExecutedDuring(function () use ($post, &$entries): void {
            $entries = $this->entriesOf($this->logs()->forRoot('post', (string) $post->getId()));
        });

        self::assertCount(
            1,
            self::selectsAmong($statements),
            \sprintf("An aggregate history must cost one read. Executed:\n%s", self::describeSql($statements)),
        );

        self::assertSame(
            [
                [DeepChild::class, AuditAction::Update],
                [Comment::class, AuditAction::Update],
            ],
            array_map(
                static fn (AuditEntry $entry): array => [$entry->entityClass, $entry->action],
                $entries,
            ),
        );
    }

    public function testTheHistoryIgnoresEntriesBelongingToAnotherAggregate(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $other = new Post('Difference Engine');
        $otherComment = new Comment($other, 'Another remark');
        $this->given($post, $comment, $other, $otherComment);

        $comment->edit('An edited remark');
        $otherComment->edit('Another edited remark');
        $this->em()->flush();

        $entries = $this->entriesOf($this->logs()->forRoot('post', (string) $post->getId()));

        self::assertCount(1, $entries);
        $this->assertRootIs($entries[0], 'post', (string) $post->getId(), 'Analytical Engine');
    }

    public function testASingleEntityHistoryIsAvailableOnItsOwn(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->rename('Second title');
        $this->em()->flush();

        $post->rename('Third title');
        $this->em()->flush();

        $entries = $this->entriesOf($this->logs()->forEntity(Post::class, (string) $post->getId()));

        self::assertCount(2, $entries);
        $this->assertFieldChanged($entries[0], 'title', 'Second title', 'Third title');
        $this->assertFieldChanged($entries[1], 'title', 'Analytical Engine', 'Second title');
    }

    public function testAnActorHistoryIsAvailableOnItsOwn(): void
    {
        $this->actor()->will($this->anActor(id: '77', type: 'operator', label: 'Grace Hopper'));
        $this->save(new Post('By Grace'));

        $this->actor()->will($this->anActor(id: '78', type: 'operator', label: 'Ada Lovelace'));
        $this->save(new Post('By Ada'));

        $entries = $this->entriesOf($this->logs()->forActor('operator', '77'));

        self::assertCount(1, $entries);
        $this->assertLabelIs($entries[0], 'By Grace');
    }

    /**
     * The alias `docs/reading-the-trail.md` tells readers to write their own criteria against.
     *
     * A documented alias that does not match the builder's is a page of examples that all throw, and
     * nothing else in the suite would notice: every other test uses the methods exactly as they come.
     */
    public function testTheDocumentedAliasIsTheOneTheBuilderUses(): void
    {
        self::assertSame(
            ['audit_log'],
            $this->logs()->createFeedQueryBuilder()->getRootAliases(),
            'docs/reading-the-trail.md tells readers to write their own criteria against this alias.',
        );
    }
}
