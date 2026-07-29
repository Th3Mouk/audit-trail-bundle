<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * The load-bearing test of the whole bundle: the trail and the change share one fate.
 *
 * An audit trail that can disagree with the data is worse than none, because it is trusted.
 * Two failures are possible and both are silent: a change that commits without its entry, and
 * an entry that survives a change that never happened. The only structural defence is to write
 * the entry inside the very transaction that writes the change — which is why capture computes
 * a change set on the audit row from inside `onFlush` instead of flushing again afterwards.
 *
 * Each rollback test asserts *inside* the transaction that both rows were really written first.
 * Without that, "nothing is there afterwards" would also pass if capture had simply never run.
 */
#[CoversNothing]
final class AtomicityTest extends IntegrationTestCase
{
    public function testACommittedTransactionKeepsBothTheChangeAndItsEntry(): void
    {
        $post = new Post('Committed');

        $this->inCommittedTransaction(function () use ($post): void {
            $this->save($post);
        });

        self::assertSame(1, $this->countRowsIn('fixture_posts'));
        self::assertSame(1, $this->countAuditRows());
        $this->assertOneEntry(AuditAction::Create, Post::class);
    }

    public function testARolledBackCreationTakesItsEntryWithIt(): void
    {
        $post = new Post('Rolled back');

        $this->inRolledBackTransaction(function () use ($post): void {
            $this->save($post);

            self::assertSame(1, $this->countRowsIn('fixture_posts'), 'The change should have been written.');
            self::assertSame(1, $this->countAuditRows(), 'The entry should have been written alongside it.');
        });

        self::assertSame(0, $this->countRowsIn('fixture_posts'));
        self::assertSame(0, $this->countAuditRows());
        $this->assertNothingRecorded();
    }

    public function testARolledBackUpdateTakesItsEntryWithIt(): void
    {
        $post = new Post('Original');
        $this->given($post);
        $postId = $post->getId();

        $this->inRolledBackTransaction(function () use ($post): void {
            $post->rename('Renamed');
            $this->em()->flush();

            self::assertSame(1, $this->countAuditRows(), 'The entry should have been written alongside the change.');
        });

        self::assertSame(0, $this->countAuditRows());
        $this->assertNothingRecorded();

        $reloaded = $this->em()->find(Post::class, $postId);
        self::assertNotNull($reloaded);
        self::assertSame('Original', $reloaded->getTitle());
    }

    public function testARolledBackDeletionLeavesNoEntryClaimingItHappened(): void
    {
        $post = new Post('Survivor');
        $this->given($post);
        $postId = $post->getId();

        $this->inRolledBackTransaction(function () use ($post): void {
            $this->remove($post);

            self::assertSame(0, $this->countRowsIn('fixture_posts'), 'The row should have been deleted.');
            self::assertSame(1, $this->countAuditRows(), 'The entry should have been written alongside the deletion.');
        });

        self::assertSame(1, $this->countRowsIn('fixture_posts'));
        self::assertSame(0, $this->countAuditRows());

        $survivor = $this->em()->find(Post::class, $postId);
        self::assertNotNull($survivor);
    }

    public function testOnlyTheRolledBackWorkIsLostAndEarlierCommittedEntriesRemain(): void
    {
        $kept = new Post('Kept');
        $this->save($kept);
        self::assertSame(1, $this->countAuditRows());

        $this->inRolledBackTransaction(function (): void {
            $this->save(new Post('Discarded'));
        });

        self::assertSame(1, $this->countRowsIn('fixture_posts'));
        $this->assertRecordedCount(1);
        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);
        $this->assertLabelIs($entry, 'Kept');
    }
}
