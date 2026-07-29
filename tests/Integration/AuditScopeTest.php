<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\ScopeProviderEntity;

/**
 * Where an entry belongs, denormalised so the aggregate's history is one indexed read.
 *
 * The walk happens during a flush, so it is memory-only by construction: a hop that would need
 * a query yields no root rather than a load. Proving that here — with real proxies and a real
 * identity map — is the only place where "memory-only" means anything.
 */
#[CoversNothing]
final class AuditScopeTest extends IntegrationTestCase
{
    public function testAnEntityWithoutAScopeLeavesTheRootColumnsNull(): void
    {
        $post = new Post('Analytical Engine');
        $this->given($post);

        $post->rename('The Analytical Engine');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class);

        $this->assertNoRoot($entry);
        $this->assertRootColumnsAreNull();
    }

    public function testOneHopPopulatesTheRootTypeIdentifierAndLabel(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $this->given($post, $comment);

        $comment->edit('An edited remark');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Comment::class);

        $this->assertRootIs($entry, 'post', (string) $post->getId(), 'Analytical Engine');
    }

    public function testTwoHopsReachTheSameAggregateRoot(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $child = new DeepChild($comment, 'A footnote');
        $this->given($post, $comment, $child);

        $child->annotate('An edited footnote');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, DeepChild::class);

        $this->assertRootIs($entry, 'post', (string) $post->getId(), 'Analytical Engine');
    }

    public function testTheWholeAggregateSharesOneRoot(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $child = new DeepChild($comment, 'A footnote');
        $this->given($post, $comment, $child);

        $comment->edit('An edited remark');
        $child->annotate('An edited footnote');
        $this->em()->flush();

        $children = [...$this->entriesFor(Comment::class), ...$this->entriesFor(DeepChild::class)];
        $roots = array_map(static fn (AuditEntry $entry): ?string => $entry->root?->id, $children);

        self::assertCount(2, $children);
        self::assertSame([(string) $post->getId()], array_values(array_unique($roots)));
    }

    public function testTheEscapeHatchDecidesItsOwnRoot(): void
    {
        $post = new Post('Analytical Engine');
        $anchored = new ScopeProviderEntity($post);
        $this->given($post, $anchored);

        $anchored->annotate('anchored by the entity itself');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, ScopeProviderEntity::class);

        $this->assertRootIs($entry, 'post', (string) $post->getId());
    }

    public function testTheEscapeHatchMayNameARootTheAttributeWalkCouldNeverReach(): void
    {
        $anchored = new ScopeProviderEntity();
        $this->given($anchored);

        $anchored->provideRoot(AuditRef::of(Post::class, 4217, 'Elsewhere entirely'));
        $anchored->annotate('anchored by hand');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, ScopeProviderEntity::class);

        $this->assertRootIs($entry, 'post', '4217', 'Elsewhere entirely');
    }

    public function testTheEscapeHatchMayDeclineToNameARoot(): void
    {
        $anchored = new ScopeProviderEntity();
        $this->given($anchored);

        $anchored->annotate('unanchored');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, ScopeProviderEntity::class);

        $this->assertNoRoot($entry);
    }

    public function testAnUnloadedHopYieldsAnIdentifiedRootWithoutALabelRatherThanALoad(): void
    {
        $post = new Post('Analytical Engine');
        $comment = new Comment($post, 'A first remark');
        $this->given($post, $comment);
        $this->em()->clear();

        $reloaded = $this->em()->find(Comment::class, $comment->getId());
        self::assertNotNull($reloaded);

        $reloaded->edit('An edited remark');
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Comment::class);

        $this->assertRootIs($entry, 'post', (string) $post->getId());
    }

    private function assertRootColumnsAreNull(): void
    {
        foreach ($this->auditRows() as $row) {
            self::assertNull($row['root_type']);
            self::assertNull($row['root_id']);
            self::assertNull($row['root_label']);
        }
    }
}
