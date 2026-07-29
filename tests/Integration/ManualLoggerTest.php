<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\AuditLoggerInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Author;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * The trail has to survive the changes Doctrine never sees.
 *
 * A bulk `UPDATE` in DQL, a raw `INSERT ... SELECT`, a migration: none of them produce a change
 * set, so the listener cannot know they happened. The manual logger exists for exactly those,
 * and it only earns its place if what it writes is indistinguishable from what capture writes.
 * If the two paths produced different shapes, every reader of the trail would need to know
 * which one made each row.
 */
#[CoversNothing]
final class ManualLoggerTest extends IntegrationTestCase
{
    public function testAManuallyRecordedCreationIsIndistinguishableInShapeFromACapturedOne(): void
    {
        $this->actor()->will($this->anActor(id: '77', type: 'operator', label: 'Grace Hopper'));

        $author = new Author('Ada Lovelace');
        $this->save($author);

        $captured = $this->onlyAuditRowWithoutIdentity();
        $this->forgetRecordedEntries();

        $this->audit()->created(
            Author::class,
            (string) $author->getId(),
            \is_array($captured['changes']) ? $captured['changes'] : [],
            'Ada Lovelace',
        );
        $this->em()->flush();

        self::assertSame(
            $captured,
            $this->onlyAuditRowWithoutIdentity(),
            'A reader of the trail must not be able to tell which path wrote a row.',
        );
    }

    public function testAManuallyRecordedChangeIsReadBackThroughTheSameRepository(): void
    {
        $this->audit()->updated(
            Post::class,
            4217,
            ['title' => $this->aChange('before bulk update', 'after bulk update')],
            'Bulk renamed',
            AuditScopeRef::of('post', 4217, 'Bulk renamed'),
            ['origin' => 'dql'],
        );
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Update, Post::class, entityId: 4217);

        $this->assertFieldChanged($entry, 'title', 'before bulk update', 'after bulk update');
        $this->assertLabelIs($entry, 'Bulk renamed');
        $this->assertRootIs($entry, 'post', 4217, 'Bulk renamed');
        self::assertSame(['origin' => 'dql'], $entry->metadata);
    }

    public function testAManuallyRecordedDeletionCarriesTheStateTheCallerSupplies(): void
    {
        $this->audit()->deleted(
            Post::class,
            4217,
            ['title' => 'Purged by migration', 'views' => 12],
            'Purged by migration',
        );
        $this->em()->flush();

        $entry = $this->assertOneEntry(AuditAction::Delete, Post::class, entityId: 4217);

        $this->assertFieldRecorded($entry, 'title', 'Purged by migration');
        $this->assertFieldRecorded($entry, 'views', 12);
    }

    public function testAManualEntryJoinsTheCallersTransactionRatherThanOpeningItsOwn(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->audit()->created(Post::class, 4217, ['title' => 'Rolled back']);
            $this->em()->flush();

            self::assertSame(1, $this->countAuditRows());
        });

        self::assertSame(0, $this->countAuditRows());
    }

    public function testTheManualLoggerNeverFlushesOnItsOwn(): void
    {
        $this->audit()->created(Post::class, 4217, ['title' => 'Not yet written']);

        self::assertSame(0, $this->countAuditRows(), 'When the transaction ends is the caller decision, not the logger one.');

        $this->em()->flush();

        self::assertSame(1, $this->countAuditRows());
    }

    public function testAManualEntryIsSilencedByTheGlobalKillSwitch(): void
    {
        $this->rebootWith(['enabled' => false]);

        $this->audit()->created(Post::class, 4217, ['title' => 'Never recorded']);
        $this->em()->flush();

        self::assertSame(0, $this->countAuditRows());
    }

    private function audit(): AuditLoggerInterface
    {
        $logger = $this->service(AuditLoggerInterface::class);
        \assert($logger instanceof AuditLoggerInterface);

        return $logger;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyAuditRowWithoutIdentity(): array
    {
        $rows = $this->auditRowsWithoutIdentity();

        self::assertCount(1, $rows);

        return $rows[0];
    }
}
